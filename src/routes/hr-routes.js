'use strict';

const Joi = require('joi');

const {
  ROLE_OPTIONS
} = require('../config/env');
const {
  listCandidates,
  getCandidateById,
  getAnswersForCandidate,
  deleteCandidateById,
  listQuestions,
  getQuestionById,
  getNextQuestionOrder,
  createQuestion,
  updateQuestionById,
  toggleQuestionActiveById,
  deleteQuestionById
} = require('../db');
const { buildDashboardStats } = require('../services/dashboard-service');
const { mapRecommendationLabel, buildExcelReport, buildPdfReport } = require('../exports');

const RECOMMENDATION_OPTIONS = [
  { value: 'SERVER_SPECIALIST', label: 'Server Specialist' },
  { value: 'BEVERAGE_SPECIALIST', label: 'Beverage Specialist' },
  { value: 'SENIOR_COOK', label: 'Senior Cook' },
  { value: 'INCOMPLETE', label: 'Incomplete' },
  { value: 'TIDAK_DIREKOMENDASIKAN', label: 'Tidak Direkomendasikan' }
];

const QUESTION_PAYLOAD_SCHEMA = Joi.object({
  order: Joi.number().integer().min(1).required(),
  option_a: Joi.string().trim().min(3).required(),
  option_b: Joi.string().trim().min(3).required(),
  option_c: Joi.string().trim().min(3).required(),
  option_d: Joi.string().trim().min(3).required(),
  is_active: Joi.any()
});

function registerHrRoutes(server) {
  server.route({
    method: 'GET',
    path: '/hr/dashboard',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const { search = '', role = '', recommendation = '' } = request.query;
      const candidates = listCandidates({ search, role, recommendation });
      const stats = buildDashboardStats(candidates);

      return h.view('hr-dashboard', {
        pageTitle: 'Dashboard HR - DISC',
        candidates,
        filters: { search, role, recommendation },
        recommendationOptions: RECOMMENDATION_OPTIONS,
        roleOptions: ROLE_OPTIONS,
        candidatesJson: JSON.stringify(candidates),
        roleDistributionJson: JSON.stringify(stats.roleDistribution),
        avgDiscJson: JSON.stringify(stats.avgDisc),
        mapRecommendationLabel
      });
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/api/candidates',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const { search = '', role = '', recommendation = '' } = request.query;
      const candidates = listCandidates({ search, role, recommendation });
      const stats = buildDashboardStats(candidates);
      return h.response({
        candidates,
        stats,
        filters: { search, role, recommendation }
      });
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/candidates/{id}',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const candidate = getCandidateById(Number(request.params.id));
      if (!candidate) {
        return h.response('Candidate not found').code(404);
      }

      const answers = getAnswersForCandidate(candidate.id);
      const answerMap = {};
      answers.forEach((answer) => {
        if (!answerMap[answer.question_id]) {
          answerMap[answer.question_id] = { most: '-', least: '-' };
        }
        answerMap[answer.question_id][answer.answer_type] = answer.option_code;
      });

      const answeredQuestionIds = Object.keys(answerMap).map(Number).sort((a, b) => a - b);
      const profileQuestions = answeredQuestionIds.map((id) => ({ id }));

      return h.view('candidate-profile', {
        pageTitle: `Profil Kandidat #${candidate.id}`,
        candidate,
        answers,
        answerMap,
        questions: profileQuestions,
        recommendationLabel: mapRecommendationLabel(candidate.recommendation),
        discDataJson: JSON.stringify({
          D: candidate.disc_d || 0,
          I: candidate.disc_i || 0,
          S: candidate.disc_s || 0,
          C: candidate.disc_c || 0
        }),
        roleScoreJson: JSON.stringify({
          server: candidate.score_server || 0,
          beverage: candidate.score_beverage || 0,
          cook: candidate.score_cook || 0
        })
      });
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/candidates/{id}/delete',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const candidateId = Number(request.params.id);
      if (!Number.isFinite(candidateId)) {
        return h.response('Invalid candidate id').code(400);
      }

      const deleted = deleteCandidateById(candidateId);
      if (!deleted) {
        return h.response('Candidate not found').code(404);
      }

      return h.redirect('/hr/dashboard');
    }
  });

  server.route({
    method: 'DELETE',
    path: '/hr/api/candidates/{id}',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const candidateId = Number(request.params.id);
      if (!Number.isFinite(candidateId)) {
        return h.response({ ok: false, message: 'Invalid candidate id' }).code(400);
      }

      const deleted = deleteCandidateById(candidateId);
      if (!deleted) {
        return h.response({ ok: false, message: 'Candidate not found' }).code(404);
      }

      return h.response({ ok: true });
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/questions',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const questionBank = listQuestions({ includeInactive: true });
      return h.view('hr-questions', {
        pageTitle: 'Kelola Soal DISC',
        questionBank
      });
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/questions/new',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      return h.view('hr-question-form', {
        pageTitle: 'Tambah Soal DISC',
        formTitle: 'Tambah Soal',
        actionUrl: '/hr/questions/new',
        mode: 'create',
        values: {
          order: getNextQuestionOrder(),
          is_active: true
        }
      });
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/questions/new',
    options: {
      auth: 'hr-jwt',
      validate: {
        payload: QUESTION_PAYLOAD_SCHEMA,
        failAction: async (request, h) => h.view('hr-question-form', {
          pageTitle: 'Tambah Soal DISC',
          formTitle: 'Tambah Soal',
          actionUrl: '/hr/questions/new',
          mode: 'create',
          errorMessage: 'Semua field opsi wajib diisi minimal 3 karakter.',
          values: request.payload
        }).code(400).takeover()
      }
    },
    handler: async (request, h) => {
      createQuestion({
        order: Number(request.payload.order),
        optionA: request.payload.option_a.trim(),
        optionB: request.payload.option_b.trim(),
        optionC: request.payload.option_c.trim(),
        optionD: request.payload.option_d.trim(),
        isActive: Boolean(request.payload.is_active)
      });

      return h.redirect('/hr/questions');
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/questions/{id}/edit',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const question = getQuestionById(Number(request.params.id));
      if (!question) {
        return h.response('Question not found').code(404);
      }

      return h.view('hr-question-form', {
        pageTitle: `Edit Soal #${question.id}`,
        formTitle: `Edit Soal #${question.id}`,
        actionUrl: `/hr/questions/${question.id}/edit`,
        mode: 'edit',
        values: {
          order: question.order,
          option_a: question.options.A,
          option_b: question.options.B,
          option_c: question.options.C,
          option_d: question.options.D,
          is_active: question.isActive
        }
      });
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/questions/{id}/edit',
    options: {
      auth: 'hr-jwt',
      validate: {
        payload: QUESTION_PAYLOAD_SCHEMA,
        failAction: async (request, h) => h.view('hr-question-form', {
          pageTitle: `Edit Soal #${request.params.id}`,
          formTitle: `Edit Soal #${request.params.id}`,
          actionUrl: `/hr/questions/${request.params.id}/edit`,
          mode: 'edit',
          errorMessage: 'Semua field opsi wajib diisi minimal 3 karakter.',
          values: request.payload
        }).code(400).takeover()
      }
    },
    handler: async (request, h) => {
      const updated = updateQuestionById(Number(request.params.id), {
        order: Number(request.payload.order),
        optionA: request.payload.option_a.trim(),
        optionB: request.payload.option_b.trim(),
        optionC: request.payload.option_c.trim(),
        optionD: request.payload.option_d.trim(),
        isActive: Boolean(request.payload.is_active)
      });

      if (!updated) {
        return h.response('Question not found').code(404);
      }

      return h.redirect('/hr/questions');
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/questions/{id}/toggle-active',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const changed = toggleQuestionActiveById(Number(request.params.id));
      if (!changed) {
        return h.response('Question not found').code(404);
      }
      return h.redirect('/hr/questions');
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/questions/{id}/delete',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const deleted = deleteQuestionById(Number(request.params.id));
      if (!deleted) {
        return h.response('Question not found').code(404);
      }
      return h.redirect('/hr/questions');
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/export/excel',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const buffer = await buildExcelReport(listCandidates({}));
      return h.response(buffer)
        .type('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        .header('Content-Disposition', `attachment; filename="disc-report-${Date.now()}.xlsx"`);
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/export/pdf',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      const buffer = await buildPdfReport(listCandidates({}));
      return h.response(buffer)
        .type('application/pdf')
        .header('Content-Disposition', `attachment; filename="disc-report-${Date.now()}.pdf"`);
    }
  });
}

module.exports = {
  registerHrRoutes
};

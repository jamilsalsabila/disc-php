'use strict';

const { OPTION_TO_DISC } = require('../questions');
const { MIN_COMPLETION_RATIO } = require('../config/env');

function toDiscPayload(answers) {
  return Object.fromEntries(
    Object.entries(answers).map(([qid, payload]) => [
      qid,
      { mostDisc: payload.most.disc, leastDisc: payload.least.disc }
    ])
  );
}

function buildIncompleteEvaluation(evaluation, answeredCount, totalQuestions) {
  return {
    ...evaluation,
    recommendation: 'INCOMPLETE',
    roleScores: {
      SERVER_SPECIALIST: 0,
      BEVERAGE_SPECIALIST: 0,
      SENIOR_COOK: 0
    },
    reason: `Jawaban valid hanya ${answeredCount}/${totalQuestions} nomor (< ${Math.ceil(MIN_COMPLETION_RATIO * 100)}%), sehingga hasil ditandai Incomplete dan belum dapat digunakan untuk rekomendasi role.`
  };
}

function buildAnswersFromPayload(payload, testQuestions) {
  const answers = {};

  testQuestions.forEach((question) => {
    const mostOptionCode = payload[`q_${question.id}_most`];
    const leastOptionCode = payload[`q_${question.id}_least`];

    if (
      mostOptionCode &&
      leastOptionCode &&
      mostOptionCode !== leastOptionCode &&
      OPTION_TO_DISC[mostOptionCode] &&
      OPTION_TO_DISC[leastOptionCode]
    ) {
      answers[question.id] = {
        most: {
          optionCode: mostOptionCode,
          disc: OPTION_TO_DISC[mostOptionCode]
        },
        least: {
          optionCode: leastOptionCode,
          disc: OPTION_TO_DISC[leastOptionCode]
        }
      };
    }
  });

  return answers;
}

module.exports = {
  toDiscPayload,
  buildIncompleteEvaluation,
  buildAnswersFromPayload
};

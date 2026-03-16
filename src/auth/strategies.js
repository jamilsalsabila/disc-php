'use strict';

const Boom = require('@hapi/boom');
const {
  IS_PROD,
  COOKIE_PASSWORD,
  HR_AUTH_DISABLED
} = require('../config/env');
const { getCandidateById } = require('../db');
const { getTokenFromRequest, verifyToken } = require('../services/hr-auth-service');

function registerAuthStrategies(server) {
  server.auth.strategy('session', 'cookie', {
    cookie: {
      name: 'disc_candidate_session',
      password: COOKIE_PASSWORD,
      isSecure: IS_PROD,
      isHttpOnly: true,
      isSameSite: 'Lax',
      ttl: 24 * 60 * 60 * 1000
    },
    redirectTo: '/',
    validate: async (request, session) => {
      if (!session || !session.candidateId) {
        return { isValid: false };
      }

      const candidate = getCandidateById(session.candidateId);
      if (!candidate) {
        return { isValid: false };
      }

      return {
        isValid: true,
        credentials: { candidateId: candidate.id }
      };
    }
  });

  server.auth.scheme('hr-jwt-scheme', () => ({
    authenticate: (request, h) => {
      if (HR_AUTH_DISABLED) {
        return h.authenticated({
          credentials: {
            role: 'hr',
            email: 'hr-auth-disabled@local',
            jti: 'auth-disabled'
          }
        });
      }

      const token = getTokenFromRequest(request);
      if (!token) {
        throw Boom.unauthorized('HR login required', 'Bearer');
      }

      try {
        const payload = verifyToken(token);
        return h.authenticated({
          credentials: {
            role: 'hr',
            email: payload.email,
            jti: payload.jti
          }
        });
      } catch (err) {
        throw Boom.unauthorized('Invalid or expired HR token', 'Bearer');
      }
    }
  }));

  server.auth.strategy('hr-jwt', 'hr-jwt-scheme');
}

module.exports = {
  registerAuthStrategies
};

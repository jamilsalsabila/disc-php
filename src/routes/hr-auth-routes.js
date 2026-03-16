'use strict';

const Joi = require('joi');
const { HR_AUTH_DISABLED } = require('../config/env');
const {
  getClientIp,
  isLoginBlocked,
  registerLoginFailure,
  clearLoginFailures,
  verifyCredentials,
  generateToken
} = require('../services/hr-auth-service');

function registerHrAuthRoutes(server) {
  server.route({
    method: 'GET',
    path: '/hr',
    options: { auth: { mode: 'try', strategy: 'hr-jwt' } },
    handler: async (request, h) => {
      if (HR_AUTH_DISABLED || request.auth.isAuthenticated) {
        return h.redirect('/hr/dashboard');
      }
      return h.redirect('/hr/login');
    }
  });

  server.route({
    method: 'GET',
    path: '/hr/login',
    options: { auth: { mode: 'try', strategy: 'hr-jwt' } },
    handler: async (request, h) => {
      if (HR_AUTH_DISABLED || request.auth.isAuthenticated) {
        return h.redirect('/hr/dashboard');
      }

      return h.view('hr-login', {
        pageTitle: 'Login HR'
      });
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/login',
    options: {
      auth: { mode: 'try', strategy: 'hr-jwt' },
      validate: {
        payload: Joi.object({
          email: Joi.string().trim().email({ tlds: { allow: false } }).required(),
          password: Joi.string().min(8).max(128).required()
        }),
        failAction: async (request, h) => h.view('hr-login', {
          pageTitle: 'Login HR',
          errorMessage: 'Format email atau password tidak valid.',
          values: { email: request.payload?.email || '' }
        }).code(400).takeover()
      }
    },
    handler: async (request, h) => {
      if (HR_AUTH_DISABLED || request.auth.isAuthenticated) {
        return h.redirect('/hr/dashboard');
      }

      const ip = getClientIp(request);
      const blockState = isLoginBlocked(ip);
      if (blockState.blocked) {
        return h.view('hr-login', {
          pageTitle: 'Login HR',
          errorMessage: `Terlalu banyak percobaan login. Coba lagi dalam ${blockState.retryAfterSec} detik.`,
          values: { email: request.payload.email }
        }).code(429);
      }

      const valid = await verifyCredentials(request.payload.email, request.payload.password);
      if (!valid) {
        registerLoginFailure(ip);
        return h.view('hr-login', {
          pageTitle: 'Login HR',
          errorMessage: 'Email atau password HR salah.',
          values: { email: request.payload.email }
        }).code(401);
      }

      clearLoginFailures(ip);
      h.state('hr_access_token', generateToken());
      return h.redirect('/hr/dashboard');
    }
  });

  server.route({
    method: 'POST',
    path: '/hr/logout',
    options: { auth: 'hr-jwt' },
    handler: async (request, h) => {
      h.unstate('hr_access_token', { path: '/hr' });
      return h.redirect('/hr/login');
    }
  });
}

module.exports = {
  registerHrAuthRoutes
};

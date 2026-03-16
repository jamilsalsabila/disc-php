'use strict';

const Path = require('path');
const Hapi = require('@hapi/hapi');
const Jwt = require('@hapi/jwt');
const Vision = require('@hapi/vision');
const Inert = require('@hapi/inert');
const Cookie = require('@hapi/cookie');

const { PORT, IS_PROD, HR_JWT_SECRET, COOKIE_PASSWORD } = require('../config/env');
const { registerViewHelpers } = require('./view-helpers');
const { registerCookieStates, registerSecurityHeaders } = require('./security');
const { registerAuthStrategies } = require('../auth/strategies');
const { registerCandidateRoutes } = require('../routes/candidate-routes');
const { registerHrAuthRoutes } = require('../routes/hr-auth-routes');
const { registerHrRoutes } = require('../routes/hr-routes');

async function createServer() {
  if (IS_PROD && (!HR_JWT_SECRET || HR_JWT_SECRET.length < 32)) {
    throw new Error('HR_JWT_SECRET must be set and at least 32 chars in production.');
  }

  if (IS_PROD && (!COOKIE_PASSWORD || COOKIE_PASSWORD.length < 32)) {
    throw new Error('COOKIE_PASSWORD must be set and at least 32 chars in production.');
  }

  const server = Hapi.server({
    port: PORT,
    host: '0.0.0.0',
    routes: {
      files: {
        relativeTo: Path.join(__dirname, '..', '..', 'public')
      }
    }
  });

  await server.register([Vision, Inert, Cookie, Jwt]);

  registerCookieStates(server);
  registerSecurityHeaders(server);
  registerAuthStrategies(server);

  const Handlebars = registerViewHelpers();

  server.views({
    engines: { hbs: Handlebars },
    path: Path.join(__dirname, '..', 'views'),
    layoutPath: Path.join(__dirname, '..', 'views', 'layout'),
    layout: 'main',
    isCached: false
  });

  server.route({
    method: 'GET',
    path: '/public/{param*}',
    handler: {
      directory: {
        path: '.',
        redirectToSlash: true
      }
    }
  });

  registerCandidateRoutes(server);
  registerHrAuthRoutes(server);
  registerHrRoutes(server);

  return server;
}

module.exports = {
  createServer
};

'use strict';

const { IS_PROD, HR_JWT_TTL_SECONDS } = require('../config/env');

function registerCookieStates(server) {
  server.state('disc_browser_token', {
    ttl: 30 * 24 * 60 * 60 * 1000,
    isSecure: IS_PROD,
    isHttpOnly: true,
    isSameSite: 'Lax',
    path: '/'
  });

  server.state('hr_access_token', {
    ttl: HR_JWT_TTL_SECONDS * 1000,
    isSecure: IS_PROD,
    isHttpOnly: true,
    isSameSite: 'Strict',
    path: '/hr'
  });
}

function registerSecurityHeaders(server) {
  server.ext('onPreResponse', (request, h) => {
    const response = request.response;
    if (response && response.isBoom) {
      if (
        response.output?.statusCode === 401 &&
        request.path.startsWith('/hr') &&
        !request.path.startsWith('/hr/api') &&
        request.path !== '/hr/login'
      ) {
        return h.redirect('/hr/login').takeover();
      }
      return h.continue;
    }

    const contentType = response?.headers?.['content-type'] || '';
    if (typeof contentType === 'string' && contentType.includes('text/html')) {
      response.header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
      response.header('Pragma', 'no-cache');
      response.header('Expires', '0');
    }

    response.header('X-Content-Type-Options', 'nosniff');
    response.header('X-Frame-Options', 'DENY');
    response.header('Referrer-Policy', 'strict-origin-when-cross-origin');
    response.header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    return h.continue;
  });
}

module.exports = {
  registerCookieStates,
  registerSecurityHeaders
};

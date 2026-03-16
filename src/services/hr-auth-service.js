'use strict';

const Crypto = require('crypto');
const Jwt = require('@hapi/jwt');
const Bcrypt = require('bcryptjs');

const {
  HR_LOGIN_EMAIL,
  HR_PASSWORD_HASH,
  HR_JWT_SECRET,
  HR_JWT_TTL_SECONDS,
  HR_LOGIN_MAX_ATTEMPTS,
  HR_LOGIN_WINDOW_MS,
  HR_LOGIN_LOCK_MS
} = require('../config/env');

const attempts = new Map();

function getClientIp(request) {
  return request.info.remoteAddress || 'unknown';
}

function getAttemptEntry(ip) {
  const now = Date.now();
  const entry = attempts.get(ip);

  if (!entry) {
    return { count: 0, windowStart: now, blockedUntil: 0 };
  }

  if (entry.blockedUntil && entry.blockedUntil <= now) {
    return { count: 0, windowStart: now, blockedUntil: 0 };
  }

  if (now - entry.windowStart > HR_LOGIN_WINDOW_MS) {
    return { count: 0, windowStart: now, blockedUntil: 0 };
  }

  return entry;
}

function isLoginBlocked(ip) {
  const entry = getAttemptEntry(ip);
  if (entry.blockedUntil && entry.blockedUntil > Date.now()) {
    return { blocked: true, retryAfterSec: Math.ceil((entry.blockedUntil - Date.now()) / 1000) };
  }
  return { blocked: false, retryAfterSec: 0 };
}

function registerLoginFailure(ip) {
  const now = Date.now();
  const entry = getAttemptEntry(ip);
  const nextCount = entry.count + 1;

  const nextEntry = {
    count: nextCount,
    windowStart: entry.windowStart || now,
    blockedUntil: 0
  };

  if (nextCount >= HR_LOGIN_MAX_ATTEMPTS) {
    nextEntry.blockedUntil = now + HR_LOGIN_LOCK_MS;
  }

  attempts.set(ip, nextEntry);
}

function clearLoginFailures(ip) {
  attempts.delete(ip);
}

function secureEqualString(a, b) {
  const bufA = Buffer.from(String(a));
  const bufB = Buffer.from(String(b));
  if (bufA.length !== bufB.length) {
    return false;
  }
  return Crypto.timingSafeEqual(bufA, bufB);
}

async function verifyCredentials(email, password) {
  if (!secureEqualString(email.toLowerCase(), HR_LOGIN_EMAIL.toLowerCase())) {
    return false;
  }

  if (HR_PASSWORD_HASH.startsWith('$2a$') || HR_PASSWORD_HASH.startsWith('$2b$') || HR_PASSWORD_HASH.startsWith('$2y$')) {
    return Bcrypt.compare(password, HR_PASSWORD_HASH);
  }

  return secureEqualString(password, HR_PASSWORD_HASH);
}

function extractBearerToken(request) {
  const header = request.headers.authorization;
  if (!header) {
    return null;
  }
  const match = /^Bearer\s+(.+)$/i.exec(header);
  return match ? match[1] : null;
}

function getTokenFromRequest(request) {
  return request.state.hr_access_token || extractBearerToken(request) || null;
}

function generateToken() {
  return Jwt.token.generate(
    {
      scope: 'hr',
      email: HR_LOGIN_EMAIL,
      jti: Crypto.randomUUID()
    },
    {
      key: HR_JWT_SECRET,
      algorithm: 'HS256'
    },
    {
      ttlSec: HR_JWT_TTL_SECONDS
    }
  );
}

function verifyToken(token) {
  const decoded = Jwt.token.decode(token);
  Jwt.token.verify(decoded, { key: HR_JWT_SECRET, algorithm: 'HS256' });
  const payload = decoded.decoded.payload;
  if (!payload || payload.scope !== 'hr' || !payload.email) {
    throw new Error('Invalid HR token payload');
  }
  return payload;
}

module.exports = {
  getClientIp,
  isLoginBlocked,
  registerLoginFailure,
  clearLoginFailures,
  verifyCredentials,
  getTokenFromRequest,
  generateToken,
  verifyToken
};

'use strict';

const Path = require('path');
require('dotenv').config({ path: Path.join(process.cwd(), '.env'), quiet: true });

const IS_PROD = process.env.NODE_ENV === 'production';

module.exports = {
  NODE_ENV: process.env.NODE_ENV || 'development',
  PORT: Number(process.env.PORT || 3000),
  IS_PROD,
  COOKIE_PASSWORD: process.env.COOKIE_PASSWORD || 'disc-cookie-password-min-32-char!',
  HR_LOGIN_EMAIL: process.env.HR_LOGIN_EMAIL || 'hr@disc.local',
  HR_PASSWORD_HASH: process.env.HR_PASSWORD_HASH || '$2b$10$txN96OIJRG.tmEToCLg/qu5.f6v.2BQx0x1pC40YSJCEHKBA2N.dy',
  HR_JWT_SECRET: process.env.HR_JWT_SECRET || 'change-this-jwt-secret-minimum-32-chars',
  HR_AUTH_DISABLED: String(process.env.HR_AUTH_DISABLED || '').toLowerCase() === 'true',
  HR_JWT_TTL_SECONDS: 8 * 60 * 60,
  HR_LOGIN_MAX_ATTEMPTS: 5,
  HR_LOGIN_WINDOW_MS: 15 * 60 * 1000,
  HR_LOGIN_LOCK_MS: 15 * 60 * 1000,
  TEST_DURATION_MINUTES: 10,
  MIN_COMPLETION_RATIO: 0.8,
  ROLE_OPTIONS: ['Server Specialist', 'Beverage Specialist', 'Senior Cook']
};

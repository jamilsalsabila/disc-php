'use strict';

function nowIso() {
  return new Date().toISOString();
}

function secondsBetween(startIso, endIso) {
  return Math.max(0, Math.floor((new Date(endIso) - new Date(startIso)) / 1000));
}

module.exports = {
  nowIso,
  secondsBetween
};

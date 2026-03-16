'use strict';

const Handlebars = require('handlebars');
const { mapRecommendationLabel } = require('../exports');

function registerViewHelpers() {
  Handlebars.registerHelper('eq', (a, b) => a === b);
  Handlebars.registerHelper('json', (value) => JSON.stringify(value));
  Handlebars.registerHelper('inc', (value) => Number(value) + 1);
  Handlebars.registerHelper('mapRec', (value) => mapRecommendationLabel(value));
  Handlebars.registerHelper('formatDate', (isoString) => {
    if (!isoString) {
      return '-';
    }
    const date = new Date(isoString);
    return new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short'
    }).format(date);
  });

  return Handlebars;
}

module.exports = {
  registerViewHelpers
};

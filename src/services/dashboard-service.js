'use strict';

function buildDashboardStats(candidates) {
  const roleCounter = new Map();
  let dSum = 0;
  let iSum = 0;
  let sSum = 0;
  let cSum = 0;
  let totalScored = 0;

  candidates.forEach((candidate) => {
    if (candidate.recommendation) {
      roleCounter.set(candidate.recommendation, (roleCounter.get(candidate.recommendation) || 0) + 1);
    }

    if (candidate.status !== 'in_progress') {
      dSum += candidate.disc_d || 0;
      iSum += candidate.disc_i || 0;
      sSum += candidate.disc_s || 0;
      cSum += candidate.disc_c || 0;
      totalScored += 1;
    }
  });

  return {
    roleDistribution: Array.from(roleCounter.entries()).map(([recommendation, total]) => ({ recommendation, total })),
    avgDisc: {
      avg_d: totalScored ? dSum / totalScored : 0,
      avg_i: totalScored ? iSum / totalScored : 0,
      avg_s: totalScored ? sSum / totalScored : 0,
      avg_c: totalScored ? cSum / totalScored : 0,
      total_submitted: totalScored
    }
  };
}

module.exports = {
  buildDashboardStats
};

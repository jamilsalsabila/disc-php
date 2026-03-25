(function () {
  const discData = window.discData || { D: 0, I: 0, S: 0, C: 0 };
  const roleData = window.roleScoreData || {};
  const isMobile = window.matchMedia('(max-width: 860px)').matches;
  const roleLabelsMap = {
    FLOOR_CREW: 'Floor Crew ( Server, Runner, Housekeeping )',
    BAR_CREW: 'Bar Crew',
    KITCHEN_CREW: 'Kitchen Crew ( Cook, Cook Helper, Steward )',
    MANAGER: 'Manager',
    BACK_OFFICE: 'Back Office ( Admin )',
    // Legacy codes
    SERVER_SPECIALIST: 'Floor Crew ( Server, Runner, Housekeeping )',
    BEVERAGE_SPECIALIST: 'Bar Crew',
    SENIOR_COOK: 'Kitchen Crew ( Cook, Cook Helper, Steward )',
    ASSISTANT_MANAGER: 'Manager',
    OPERATIONS_ADMIN: 'Back Office ( Admin )'
  };

  const radar = document.getElementById('discRadar');
  if (radar) {
    const radarCtx = radar.getContext('2d');
    const radarGradient = radarCtx.createLinearGradient(0, 0, 0, 280);
    radarGradient.addColorStop(0, 'rgba(14, 165, 233, 0.45)');
    radarGradient.addColorStop(1, 'rgba(14, 165, 233, 0.08)');

    new Chart(radar, {
      type: 'radar',
      data: {
        labels: ['Dominance', 'Influence', 'Steadiness', 'Conscientiousness'],
        datasets: [{
          label: 'Skor DISC',
          data: [discData.D, discData.I, discData.S, discData.C],
          borderColor: '#0369A1',
          borderWidth: 2.5,
          pointRadius: isMobile ? 3 : 4,
          pointHoverRadius: isMobile ? 4 : 6,
          pointBackgroundColor: '#0284C7',
          pointBorderColor: '#F8FAFC',
          pointBorderWidth: 2,
          backgroundColor: radarGradient
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (context) => ` ${context.label}: ${context.parsed.r}`
            }
          }
        },
        scales: {
          r: {
            beginAtZero: true,
            suggestedMax: 48,
            ticks: {
              stepSize: 8,
              backdropColor: 'transparent',
              color: '#64748B'
            },
            grid: { color: '#E2E8F0' },
            angleLines: { color: '#E2E8F0' },
            pointLabels: {
              color: '#334155',
              font: {
                size: isMobile ? 11 : 12,
                weight: '600'
              }
            }
          }
        }
      }
    });
  }

  const bar = document.getElementById('roleBar');
  if (bar) {
    const barCtx = bar.getContext('2d');
    const roleEntries = Object.entries(roleData).filter((entry) => Number.isFinite(Number(entry[1])));
    const labels = roleEntries.map((entry) => roleLabelsMap[entry[0]] || entry[0]);
    const values = roleEntries.map((entry) => Number(entry[1]));

    const palette = [
      ['#FB923C', '#EA580C'],
      ['#38BDF8', '#0284C7'],
      ['#2DD4BF', '#0F766E'],
      ['#A78BFA', '#6D28D9'],
      ['#F472B6', '#BE185D'],
      ['#FACC15', '#CA8A04']
    ];
    const gradients = labels.map((_, idx) => {
      const pair = palette[idx % palette.length];
      const gradient = barCtx.createLinearGradient(0, 0, 0, 260);
      gradient.addColorStop(0, pair[0]);
      gradient.addColorStop(1, pair[1]);
      return gradient;
    });

    new Chart(bar, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Role Fit %',
          data: values,
          backgroundColor: gradients,
          borderRadius: 12,
          maxBarThickness: isMobile ? 36 : 50
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (context) => ` ${context.parsed.y}%`
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            suggestedMax: 100,
            ticks: {
              callback: (value) => `${value}%`
            },
            grid: { color: '#E2E8F0' }
          },
          x: {
            grid: { display: false },
            ticks: { color: '#334155' }
          }
        }
      }
    });
  }
})();

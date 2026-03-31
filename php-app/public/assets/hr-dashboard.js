(function () {
  const base = window.routeBase || '';
  const withBase = (path) => `${base}${path}`;
  const isMobile = window.matchMedia('(max-width: 860px)').matches;
  const filterForm = document.getElementById('hr-filter-form');
  const tableBody = document.getElementById('candidate-table-body');
  const timeoutRefreshBtn = document.getElementById('timeout-refresh-btn');
  let toastStack = null;

  let roleChart;
  let discChart;

  const roleLabels = {
    MANAGER: 'Manager',
    BACK_OFFICE: 'Back Office',
    HEAD_KITCHEN: 'Head Kitchen',
    HEAD_BAR: 'Head Bar',
    FLOOR_CAPTAIN: 'Floor Captain',
    COOK: 'Cook',
    COOK_HELPER: 'Cook Helper',
    STEWARD: 'Steward',
    MIXOLOGIST: 'Mixologist',
    SERVER: 'Server',
    HOUSEKEEPING: 'Housekeeping',
    // Legacy codes
    SERVER_SPECIALIST: 'Server',
    BEVERAGE_SPECIALIST: 'Mixologist',
    SENIOR_COOK: 'Cook',
    ASSISTANT_MANAGER: 'Manager',
    OPERATIONS_ADMIN: 'Back Office',
    FLOOR_CREW: 'Server',
    BAR_CREW: 'Mixologist',
    KITCHEN_CREW: 'Cook',
    INCOMPLETE: 'Incomplete',
    TIDAK_DIREKOMENDASIKAN: 'Tidak Direkomendasikan'
  };

  function mapRec(value) {
    return roleLabels[value] || '-';
  }

  function normalizeRoleData(roleData) {
    const merged = new Map();
    (roleData || []).forEach((item) => {
      const label = mapRec(item.recommendation);
      const total = Number(item.total || 0);
      merged.set(label, (merged.get(label) || 0) + total);
    });
    return Array.from(merged.entries()).map(([label, total]) => ({ label, total }));
  }

  function buildRoleColors(count) {
    const colors = [];
    for (let i = 0; i < count; i += 1) {
      const hue = Math.round((360 / Math.max(1, count)) * i);
      colors.push(`hsl(${hue} 74% 52%)`);
    }
    return colors;
  }

  function renderRoleLegend(labels, values, colors) {
    const chartCard = document.querySelector('.chart-card-role');
    if (!chartCard) return;

    let legend = chartCard.querySelector('#roleChartLegend');
    if (!legend) {
      legend = document.createElement('div');
      legend.id = 'roleChartLegend';
      legend.className = 'role-legend';
      chartCard.appendChild(legend);
    }

    if (!labels.length) {
      legend.innerHTML = '';
      return;
    }

    legend.innerHTML = labels
      .map((label, idx) => (
        `<div class="role-legend-item">
          <span class="role-legend-dot" style="background:${colors[idx]}"></span>
          <span class="role-legend-text">${escapeHtml(label)} (${values[idx]})</span>
        </div>`
      ))
      .join('');
  }

  function escapeHtml(value) {
    const str = String(value ?? '');
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderRoleChart(roleData) {
    const roleCtx = document.getElementById('roleChart');
    if (!roleCtx) return;

    if (roleChart) {
      roleChart.destroy();
    }

    const normalized = normalizeRoleData(roleData);
    const labels = normalized.map((r) => r.label);
    const values = normalized.map((r) => r.total);
    const colors = values.length ? buildRoleColors(values.length) : ['#CBD5E1'];
    renderRoleLegend(labels, values, colors);

    roleChart = new Chart(roleCtx, {
      type: 'doughnut',
      data: {
        labels: labels.length ? labels : ['Belum ada data'],
        datasets: [{
          data: values.length ? values : [1],
          backgroundColor: colors,
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        radius: isMobile ? '84%' : '74%',
        plugins: {
          legend: {
            display: false
          }
        }
      }
    });
  }

  function renderDiscChart(avgDisc) {
    const discCtx = document.getElementById('discChart');
    if (!discCtx) return;

    if (discChart) {
      discChart.destroy();
    }

    discChart = new Chart(discCtx, {
      type: 'bar',
      data: {
        labels: ['D', 'I', 'S', 'C'],
        datasets: [{
          label: 'Rata-rata',
          data: [avgDisc.avg_d || 0, avgDisc.avg_i || 0, avgDisc.avg_s || 0, avgDisc.avg_c || 0],
          backgroundColor: ['#FB7185', '#FACC15', '#2DD4BF', '#60A5FA'],
          borderRadius: 10,
          maxBarThickness: isMobile ? 36 : 48
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            suggestedMax: 48,
            ticks: { stepSize: 8 },
            grid: { color: '#E2E8F0' }
          },
          x: {
            grid: { display: false }
          }
        }
      }
    });
  }

  function attachRowHandlers() {
    const rows = document.querySelectorAll('.candidate-row[data-href]');
    rows.forEach((row) => {
      row.addEventListener('click', (event) => {
        const interactive = event.target.closest('a, button, input, select, textarea');
        if (interactive) {
          return;
        }
        const href = row.getAttribute('data-href');
        if (href) {
          window.location.href = href;
        }
      });
    });
  }

  function renderCandidateTable(candidates) {
    if (!tableBody) {
      return;
    }

    if (!candidates.length) {
      tableBody.innerHTML = '<tr><td colspan="7">Belum ada data kandidat.</td></tr>';
      return;
    }

    tableBody.innerHTML = candidates.map((candidate) => {
      return `
        <tr class="candidate-row" data-href="${withBase(`/hr/candidates/${candidate.id}`)}">
          <td>#${candidate.id}</td>
          <td>
            <strong>${escapeHtml(candidate.full_name)}</strong><br>
            <small>${escapeHtml(candidate.email)}</small>
          </td>
          <td>${escapeHtml(candidate.selected_role)}</td>
          <td>${escapeHtml(mapRec(candidate.recommendation))}</td>
          <td>${escapeHtml(candidate.interview_recommendation || '-')}</td>
          <td>${escapeHtml(candidate.status)}</td>
          <td>
            <div class="table-actions">
              <a href="${withBase(`/hr/candidates/${candidate.id}`)}" class="table-link btn-detail action-btn">Detail Profil</a>
              <button type="button" class="btn-danger-outline delete-candidate-btn action-btn" data-id="${candidate.id}" data-name="${escapeHtml(candidate.full_name)}">Hapus</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    attachRowHandlers();
  }

  function renderDashboard(payload) {
    renderCandidateTable(payload.candidates || []);
    renderRoleChart((payload.stats && payload.stats.roleDistribution) || []);
    renderDiscChart((payload.stats && payload.stats.avgDisc) || {});
  }

  function getToastStack() {
    if (toastStack) {
      return toastStack;
    }
    toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    document.body.appendChild(toastStack);
    return toastStack;
  }

  function showToast(message, type = 'info') {
    const stack = getToastStack();
    const toast = document.createElement('div');
    toast.className = `toast-item is-${type}`;
    toast.textContent = message;
    stack.appendChild(toast);
    requestAnimationFrame(() => {
      toast.classList.add('is-show');
    });
    setTimeout(() => {
      toast.classList.remove('is-show');
      setTimeout(() => {
        toast.remove();
      }, 200);
    }, 2400);
  }

  async function fetchAndRender() {
    if (!filterForm) return;
    const params = new URLSearchParams(new FormData(filterForm));
    const qs = params.toString();

    const response = await fetch(withBase(`/hr/api/candidates?${qs}`), {
      headers: { Accept: 'application/json' }
    });
    if (response.status === 401) {
      window.location.href = withBase('/hr/login');
      return;
    }
    if (!response.ok) {
      return;
    }

    const payload = await response.json();
    renderDashboard(payload);

    const nextUrl = qs ? withBase(`/hr/dashboard?${qs}`) : withBase('/hr/dashboard');
    window.history.replaceState({}, '', nextUrl);
    return payload;
  }

  function setupLiveFilters() {
    if (!filterForm) return;
    const searchInput = filterForm.querySelector('input[name="search"]');
    const roleSelect = filterForm.querySelector('select[name="role"]');
    const recommendationSelect = filterForm.querySelector('select[name="recommendation"]');
    const resetButton = document.getElementById('reset-filter-btn');

    let debounceTimer;
    const submitWithDebounce = () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        fetchAndRender();
      }, 350);
    };

    if (searchInput) {
      searchInput.addEventListener('input', submitWithDebounce);
    }
    if (roleSelect) {
      roleSelect.addEventListener('change', fetchAndRender);
    }
    if (recommendationSelect) {
      recommendationSelect.addEventListener('change', fetchAndRender);
    }

    filterForm.addEventListener('submit', (event) => {
      event.preventDefault();
      fetchAndRender();
    });

    if (resetButton) {
      resetButton.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (roleSelect) roleSelect.value = '';
        if (recommendationSelect) recommendationSelect.value = '';
        fetchAndRender();
      });
    }
  }

  function setupDeleteHandlers() {
    if (!tableBody) return;
    tableBody.addEventListener('click', async (event) => {
      const btn = event.target.closest('.delete-candidate-btn');
      if (!btn) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();

      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || '';
      const confirmed = window.confirm(`Hapus hasil tes kandidat "${name}"? Tindakan ini tidak bisa dibatalkan.`);
      if (!confirmed) {
        return;
      }

      const response = await fetch(withBase(`/hr/api/candidates/${id}`), {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': window.csrfToken || ''
        }
      });

      if (response.status === 401) {
        window.location.href = withBase('/hr/login');
        return;
      }
      if (!response.ok) {
        showToast('Gagal menghapus data kandidat. Silakan coba lagi.', 'error');
        return;
      }

      showToast('Data kandidat berhasil dihapus.', 'success');
      await fetchAndRender();
    });
  }

  function setupTimeoutRefreshHandler() {
    if (!timeoutRefreshBtn) return;
    timeoutRefreshBtn.addEventListener('click', async () => {
      if (timeoutRefreshBtn.disabled) {
        return;
      }

      timeoutRefreshBtn.disabled = true;
      showToast('Memproses refresh status...', 'info');

      try {
        const response = await fetch(withBase('/hr/api/refresh-timeouts'), {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-Token': window.csrfToken || ''
          }
        });

        if (response.status === 401) {
          window.location.href = withBase('/hr/login');
          return;
        }

        if (!response.ok) {
          throw new Error('Request failed');
        }

        const payload = await response.json();
        showToast(payload.message || 'Status berhasil diperbarui.', 'success');
        await fetchAndRender();
      } catch (error) {
        showToast('Gagal update status. Coba lagi.', 'error');
      } finally {
        timeoutRefreshBtn.disabled = false;
      }
    });
  }

  renderRoleChart(window.roleDistribution || []);
  renderDiscChart(window.avgDisc || {});
  attachRowHandlers();
  setupLiveFilters();
  setupDeleteHandlers();
  setupTimeoutRefreshHandler();
})();

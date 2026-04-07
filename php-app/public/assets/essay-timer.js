(function () {
  const container = document.querySelector('.test-page');
  const countdownEl = document.getElementById('essay-countdown');
  const form = document.getElementById('essay-form');
  const autosaveStatusEl = document.getElementById('essay-autosave-status');

  if (!container || !countdownEl || !form) {
    return;
  }
  document.body.classList.add('has-floating-timer');

  const deadline = new Date(container.dataset.deadline).getTime();
  const textareas = Array.from(form.querySelectorAll('textarea[name^="essay_"]'));
  const progressUrl = form.action.replace(/\/essay-submit(?:\?.*)?$/, '/essay-progress-save');
  const typingUrl = form.action.replace(/\/essay-submit(?:\?.*)?$/, '/typing-metrics-save');
  const integrityUrl = form.action.replace(/\/essay-submit(?:\?.*)?$/, '/integrity-signal');
  const eventUrl = form.action.replace(/\/essay-submit(?:\?.*)?$/, '/integrity-event');

  let autosaveTimer = null;
  let autosaveInFlight = false;
  let autosaveQueued = false;
  let hasAutoSubmitted = false;
  let isAutoSubmitting = false;
  let typingSaveInFlight = false;
  let typingSaveQueued = false;
  let lastVisibilitySignalAt = 0;

  const metricsByQuestion = {};
  textareas.forEach((ta) => {
    const m = /essay_(\d+)/.exec(ta.name);
    if (!m) return;
    const qid = Number(m[1]);
    metricsByQuestion[qid] = {
      keystrokes: 0,
      input_events: 0,
      paste_count: 0,
      focus_count: 0,
      blur_count: 0,
      active_ms: 0,
      total_chars: ta.value ? ta.value.length : 0,
      last_input_at: '',
      _lastInputTs: null,
      _focusStartTs: null
    };
  });

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function formatSavedAt(iso) {
    if (!iso) return '';
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return '';
    return `${pad(dt.getHours())}:${pad(dt.getMinutes())}:${pad(dt.getSeconds())}`;
  }

  function setAutosaveStatus(state, text) {
    if (!autosaveStatusEl) {
      return;
    }
    autosaveStatusEl.textContent = text;
    autosaveStatusEl.classList.remove('is-idle', 'is-saving', 'is-saved', 'is-error');
    autosaveStatusEl.classList.add(state);
  }

  function getCsrfToken() {
    const csrfInput = form.querySelector('input[name="_csrf"]');
    return csrfInput && csrfInput.value ? csrfInput.value : '';
  }

  function sendIntegritySignal(signal, count) {
    if (isAutoSubmitting) {
      return;
    }
    const csrf = getCsrfToken();
    if (!csrf || !signal) {
      return;
    }
    const payload = new URLSearchParams();
    payload.set('_csrf', csrf);
    payload.set('signal', signal);
    payload.set('count', String(Math.max(1, Number(count || 1))));
    const body = payload.toString();

    if (navigator.sendBeacon) {
      const blob = new Blob([body], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
      navigator.sendBeacon(integrityUrl, blob);
      return;
    }
    fetch(integrityUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      credentials: 'same-origin',
      body
    }).catch(() => {});
  }

  function sendIntegrityEvent(eventType, eventValue, meta) {
    if (isAutoSubmitting) {
      return;
    }
    const csrf = getCsrfToken();
    if (!csrf || !eventType) {
      return;
    }

    const payload = new URLSearchParams();
    payload.set('_csrf', csrf);
    payload.set('phase', 'essay');
    payload.set('event_type', eventType);
    if (eventValue) {
      payload.set('event_value', String(eventValue));
    }
    if (meta && typeof meta === 'object') {
      try {
        payload.set('meta_json', JSON.stringify(meta));
      } catch (_e) {}
    }
    const body = payload.toString();

    if (navigator.sendBeacon) {
      const blob = new Blob([body], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
      navigator.sendBeacon(eventUrl, blob);
      return;
    }
    fetch(eventUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      credentials: 'same-origin',
      body
    }).catch(() => {});
  }

  function snapshotTypingMetrics() {
    const out = {};
    Object.keys(metricsByQuestion).forEach((qid) => {
      const metric = metricsByQuestion[qid];
      out[qid] = {
        keystrokes: metric.keystrokes || 0,
        input_events: metric.input_events || 0,
        paste_count: metric.paste_count || 0,
        focus_count: metric.focus_count || 0,
        blur_count: metric.blur_count || 0,
        active_ms: metric.active_ms || 0,
        total_chars: metric.total_chars || 0,
        last_input_at: metric.last_input_at || ''
      };
    });
    return out;
  }

  function saveTypingMetrics() {
    if (isAutoSubmitting) {
      return Promise.resolve();
    }
    if (typingSaveInFlight) {
      typingSaveQueued = true;
      return Promise.resolve();
    }

    const csrf = getCsrfToken();
    if (!csrf) {
      return Promise.resolve();
    }

    typingSaveInFlight = true;
    typingSaveQueued = false;

    const payload = new URLSearchParams();
    payload.set('_csrf', csrf);
    payload.set('metrics_json', JSON.stringify(snapshotTypingMetrics()));

    return fetch(typingUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      credentials: 'same-origin',
      body: payload.toString()
    })
      .catch(() => {})
      .finally(() => {
        typingSaveInFlight = false;
        if (typingSaveQueued) {
          typingSaveQueued = false;
          saveTypingMetrics();
        }
      });
  }

  function tick() {
    const now = Date.now();
    const diffMs = deadline - now;
    if (diffMs <= 0) {
      countdownEl.textContent = '00:00';
      if (!hasAutoSubmitted) {
        sendIntegrityEvent('auto_submit_timeout', 'essay');
        hasAutoSubmitted = true;
        isAutoSubmitting = true;
        form.submit();
      }
      return;
    }

    const totalSec = Math.floor(diffMs / 1000);
    const mins = Math.floor(totalSec / 60);
    const secs = totalSec % 60;
    countdownEl.textContent = `${pad(mins)}:${pad(secs)}`;
  }

  function buildBody() {
    const params = new URLSearchParams();
    const csrfInput = form.querySelector('input[name="_csrf"]');
    if (csrfInput && csrfInput.value) {
      params.set('_csrf', csrfInput.value);
    }
    textareas.forEach((ta) => {
      params.set(ta.name, ta.value || '');
    });
    return params.toString();
  }

  function saveProgress() {
    if (isAutoSubmitting) {
      return Promise.resolve();
    }
    if (autosaveInFlight) {
      autosaveQueued = true;
      return Promise.resolve();
    }

    autosaveInFlight = true;
    autosaveQueued = false;
    setAutosaveStatus('is-saving', 'Menyimpan...');
    return fetch(progressUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      credentials: 'same-origin',
      body: buildBody()
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Autosave failed');
        }
        return response.json();
      })
      .then((payload) => {
        const savedAt = formatSavedAt(payload && payload.last_autosave_at ? payload.last_autosave_at : '');
        setAutosaveStatus('is-saved', savedAt ? `Tersimpan ${savedAt}` : 'Tersimpan');
      })
      .catch(() => {
        setAutosaveStatus('is-error', 'Gagal simpan');
      })
      .finally(() => {
        autosaveInFlight = false;
        if (autosaveQueued) {
          autosaveQueued = false;
          saveProgress();
        }
      });
  }

  function scheduleAutosave(delayMs) {
    if (isAutoSubmitting) {
      return;
    }
    clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => {
      saveProgress();
      saveTypingMetrics();
    }, delayMs);
  }

  function getQuestionIdByTextareaName(name) {
    const m = /essay_(\d+)/.exec(name || '');
    return m ? Number(m[1]) : null;
  }

  textareas.forEach((ta) => {
    ta.addEventListener('focus', () => {
      const qid = getQuestionIdByTextareaName(ta.name);
      if (!qid || !metricsByQuestion[qid]) return;
      const metric = metricsByQuestion[qid];
      metric.focus_count += 1;
      metric._focusStartTs = Date.now();
    });

    ta.addEventListener('blur', () => {
      const qid = getQuestionIdByTextareaName(ta.name);
      if (!qid || !metricsByQuestion[qid]) return;
      const metric = metricsByQuestion[qid];
      metric.blur_count += 1;
      if (metric._focusStartTs) {
        metric.active_ms += Math.max(0, Date.now() - metric._focusStartTs);
      }
      metric._focusStartTs = null;
    });

    ta.addEventListener('keydown', () => {
      const qid = getQuestionIdByTextareaName(ta.name);
      if (!qid || !metricsByQuestion[qid]) return;
      metricsByQuestion[qid].keystrokes += 1;
    });

    ta.addEventListener('input', () => {
      const qid = getQuestionIdByTextareaName(ta.name);
      if (!qid || !metricsByQuestion[qid]) return;
      const metric = metricsByQuestion[qid];
      const now = Date.now();
      metric.input_events += 1;
      metric.total_chars = (ta.value || '').length;
      metric.last_input_at = new Date().toISOString();
      if (metric._lastInputTs) {
        const delta = now - metric._lastInputTs;
        metric.active_ms += Math.max(0, Math.min(delta, 5000));
      }
      metric._lastInputTs = now;
      scheduleAutosave(700);
    });

    ta.addEventListener('paste', () => {
      const qid = getQuestionIdByTextareaName(ta.name);
      if (qid && metricsByQuestion[qid]) {
        metricsByQuestion[qid].paste_count += 1;
      }
      sendIntegritySignal('paste', 1);
      sendIntegrityEvent('paste_detected', 'essay', { qid: qid || 0 });
      scheduleAutosave(300);
    });
  });

  form.addEventListener('submit', () => {
    sendIntegrityEvent('submit_attempt', 'essay');
    saveTypingMetrics();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'hidden') {
      return;
    }
    const now = Date.now();
    if ((now - lastVisibilitySignalAt) < 2000) {
      return;
    }
    lastVisibilitySignalAt = now;
    sendIntegritySignal('tab_switch', 1);
    sendIntegrityEvent('tab_switch', 'hidden');
  });

  tick();
  setInterval(tick, 1000);
  setInterval(() => {
    scheduleAutosave(0);
  }, 15000);
  setAutosaveStatus('is-idle', 'Autosave aktif');
  sendIntegrityEvent('page_open', 'essay');

  function flushAutosaveOnExit() {
    if (isAutoSubmitting) {
      return;
    }
    const data = buildBody();
    if (navigator.sendBeacon) {
      const blob = new Blob([data], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
      navigator.sendBeacon(progressUrl, blob);
    } else {
      saveProgress();
    }
    saveTypingMetrics();
    sendIntegrityEvent('before_unload', 'essay');
  }

  window.addEventListener('beforeunload', flushAutosaveOnExit);
  window.addEventListener('pagehide', flushAutosaveOnExit);
})();

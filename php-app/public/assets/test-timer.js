(function () {
  const container = document.querySelector('.test-page');
  const countdownEl = document.getElementById('countdown');
  const form = document.getElementById('disc-form');
  const autosaveStatusEl = document.getElementById('autosave-status');

  if (!container || !countdownEl || !form) {
    return;
  }
  document.body.classList.add('has-floating-timer');

  const deadline = new Date(container.dataset.deadline).getTime();
  const radios = Array.from(form.querySelectorAll('input[type="radio"]'));
  const progressEl = document.getElementById('valid-progress');
  let hasAutoSubmitted = false;
  let isAutoSubmitting = false;
  let autosaveTimer = null;
  let autosaveInFlight = false;
  let autosaveQueued = false;
  const progressUrl = form.action.replace(/\/disc-submit(?:\?.*)?$/, '/progress-save');
  const integrityUrl = form.action.replace(/\/disc-submit(?:\?.*)?$/, '/integrity-signal');
  const eventUrl = form.action.replace(/\/disc-submit(?:\?.*)?$/, '/integrity-event');
  let toastStack = null;
  let lastVisibilitySignalAt = 0;

  const questionIds = Array.from(
    new Set(
      radios.map((radio) => {
        const m = /q_(\d+)_(most|least)/.exec(radio.name);
        return m ? m[1] : null;
      }).filter(Boolean)
    )
  );

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function tick() {
    const now = Date.now();
    const diffMs = deadline - now;

    if (diffMs <= 0) {
      countdownEl.textContent = '00:00';
      if (!hasAutoSubmitted) {
        sendIntegrityEvent('auto_submit_timeout', 'disc');
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

    const floatingTimer = document.querySelector('.floating-timer');
    if (floatingTimer) {
      if (totalSec <= 120) {
        floatingTimer.classList.add('is-warning');
      } else {
        floatingTimer.classList.remove('is-warning');
      }
    }
  }

  tick();
  setInterval(tick, 1000);

  function getQuestionState(qid) {
    const most = form.querySelector(`input[name="q_${qid}_most"]:checked`);
    const least = form.querySelector(`input[name="q_${qid}_least"]:checked`);
    return {
      most,
      least,
      isValid: Boolean(most && least && most.value !== least.value)
    };
  }

  function setAutosaveStatus(state, text) {
    if (!autosaveStatusEl) {
      return;
    }
    autosaveStatusEl.textContent = text;
    autosaveStatusEl.classList.remove('is-idle', 'is-saving', 'is-saved', 'is-error');
    autosaveStatusEl.classList.add(state);
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

  function showToast(message, type) {
    const stack = getToastStack();
    const toast = document.createElement('div');
    toast.className = `toast-item is-${type || 'info'}`;
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
    }, 2600);
  }

  function buildAutosaveBody() {
    const params = new URLSearchParams();
    const csrfInput = form.querySelector('input[name="_csrf"]');
    if (csrfInput && csrfInput.value) {
      params.set('_csrf', csrfInput.value);
    }

    questionIds.forEach((qid) => {
      const most = form.querySelector(`input[name="q_${qid}_most"]:checked`);
      const least = form.querySelector(`input[name="q_${qid}_least"]:checked`);
      if (most) {
        params.set(`q_${qid}_most`, most.value);
      }
      if (least) {
        params.set(`q_${qid}_least`, least.value);
      }
    });

    return params;
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
    payload.set('phase', 'disc');
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
      body: buildAutosaveBody().toString()
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Autosave failed');
        }
        setAutosaveStatus('is-saved', 'Tersimpan');
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
    }, delayMs);
  }

  function updateQuestionBadge(qid) {
    const badge = form.querySelector(`.question-status[data-question-id="${qid}"]`);
    if (!badge) {
      return;
    }
    const state = getQuestionState(qid);
    badge.textContent = state.isValid ? 'Valid' : 'Belum valid';
    badge.classList.toggle('is-valid', state.isValid);
    badge.classList.toggle('is-invalid', !state.isValid);
  }

  function updateProgress() {
    let validCount = 0;
    questionIds.forEach((qid) => {
      const state = getQuestionState(qid);
      if (state.isValid) {
        validCount += 1;
      }
      updateQuestionBadge(qid);
    });
    if (progressEl) {
      progressEl.textContent = `${validCount} / ${questionIds.length} nomor`;
    }
  }

  radios.forEach((radio) => {
    radio.addEventListener('change', () => {
      const match = /q_(\d+)_(most|least)/.exec(radio.name);
      if (!match) return;
      const qid = match[1];
      const type = match[2];
      const oppositeType = type === 'most' ? 'least' : 'most';
      const oppositeChecked = form.querySelector(`input[name="q_${qid}_${oppositeType}"]:checked`);
      if (oppositeChecked && oppositeChecked.value === radio.value) {
        oppositeChecked.checked = false;
      }
      updateProgress();
      scheduleAutosave(500);
    });
  });

  updateProgress();
  setAutosaveStatus('is-idle', 'Autosave aktif');
  sendIntegrityEvent('page_open', 'disc');

  form.addEventListener('submit', (event) => {
    sendIntegrityEvent('submit_attempt', 'disc');
    if (isAutoSubmitting) {
      return;
    }
    for (const qid of questionIds) {
      const state = getQuestionState(qid);
      if (!state.isValid) {
        event.preventDefault();
        sendIntegrityEvent('invalid_submit', 'disc');
        showToast('Setiap nomor wajib memilih 1 Most dan 1 Least yang berbeda.', 'error');
        updateProgress();
        return;
      }
    }
  });

  setInterval(() => {
    scheduleAutosave(0);
  }, 15000);

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

  form.addEventListener('paste', () => {
    sendIntegritySignal('paste', 1);
    sendIntegrityEvent('paste_detected', 'disc');
  });

  window.addEventListener('beforeunload', () => {
    if (isAutoSubmitting) {
      return;
    }
    const data = buildAutosaveBody().toString();
    if (navigator.sendBeacon) {
      const blob = new Blob([data], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
      navigator.sendBeacon(progressUrl, blob);
      sendIntegrityEvent('before_unload', 'disc');
      return;
    }
    sendIntegrityEvent('before_unload', 'disc');
    saveProgress();
  });
})();

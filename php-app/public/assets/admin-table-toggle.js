(function () {
  var STORAGE_KEY = 'hr_admin_table_density';

  function isAdminPage() {
    return Boolean(document.querySelector('.hr-page, .profile-page'));
  }

  function setCompactState(isCompact) {
    document.body.classList.toggle('admin-compact', isCompact);
    var buttons = document.querySelectorAll('[data-compact-toggle]');
    buttons.forEach(function (btn) {
      btn.setAttribute('aria-pressed', isCompact ? 'true' : 'false');
      btn.textContent = isCompact ? 'Tabel: Compact' : 'Tabel: Normal';
      btn.title = isCompact ? 'Klik untuk mode normal' : 'Klik untuk mode compact';
    });
  }

  function readState() {
    try {
      return localStorage.getItem(STORAGE_KEY) === 'compact';
    } catch (err) {
      return false;
    }
  }

  function writeState(isCompact) {
    try {
      localStorage.setItem(STORAGE_KEY, isCompact ? 'compact' : 'normal');
    } catch (err) {
      // ignore storage errors
    }
  }

  function init() {
    if (!isAdminPage()) {
      return;
    }

    setCompactState(readState());

    var buttons = document.querySelectorAll('[data-compact-toggle]');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = !document.body.classList.contains('admin-compact');
        setCompactState(next);
        writeState(next);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

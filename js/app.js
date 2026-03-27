(function () {
  'use strict';

  const form = document.querySelector('[data-filter-form]');
  if (!form) {
    return;
  }

  const statusField = form.querySelector('select[name="status"]');
  if (statusField) {
    statusField.addEventListener('change', () => {
      statusField.style.borderColor = statusField.value ? 'rgba(37,99,235,.45)' : 'rgba(15,23,42,.08)';
    });
  }
})();

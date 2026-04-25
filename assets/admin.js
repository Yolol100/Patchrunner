document.addEventListener('DOMContentLoaded', function () {
  var copyButtons = document.querySelectorAll('.aipr-copy-button');
  var liveRegion = document.getElementById('aipr-live-region');

  function announce(message) {
    if (!liveRegion || !message) return;
    liveRegion.textContent = '';
    window.setTimeout(function () {
      liveRegion.textContent = message;
    }, 30);
  }

  document.addEventListener('click', function (event) {
    var dismissButton = event.target.closest('[data-aipr-dismiss-notice="true"]');
    if (dismissButton) {
      var notice = dismissButton.closest('.aipr-page-notice');
      if (notice) {
        notice.hidden = true;
      }
      return;
    }

    var button = event.target.closest('.aipr-copy-button');
    if (!button) return;

    var selector = button.getAttribute('data-copy-target');
    var target = selector ? document.querySelector(selector) : null;
    if (!target) return;

    var value = 'value' in target ? target.value : target.textContent;
    var original = button.textContent;
    var copiedMessage = (window.aiprAdmin && window.aiprAdmin.copied) ? window.aiprAdmin.copied : 'Gekopieerd';
    var copyFailedMessage = (window.aiprAdmin && window.aiprAdmin.copy_failed) ? window.aiprAdmin.copy_failed : 'Kopiëren mislukt. Kopieer de JSON handmatig.';

    function setTemporaryLabel(message) {
      button.textContent = message;
      window.setTimeout(function () {
        button.textContent = original;
      }, 1800);
    }

    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
      setTemporaryLabel(copyFailedMessage);
      announce(copyFailedMessage);
      return;
    }

    navigator.clipboard.writeText(value).then(function () {
      setTemporaryLabel(copiedMessage);
      announce(copiedMessage);
    }).catch(function () {
      setTemporaryLabel(copyFailedMessage);
      announce(copyFailedMessage);
    });
  });
});

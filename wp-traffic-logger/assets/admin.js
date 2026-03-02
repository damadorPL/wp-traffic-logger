(function () {
  var isInitialized = false;

  function decodeEntryPayload(encoded) {
    if (!encoded) {
      return '';
    }

    var decoded = '';
    try {
      decoded = window.atob(encoded);
    } catch (err) {
      return '';
    }

    try {
      if (decoded.length > 200000) {
        return decoded;
      }
      var parsed = JSON.parse(decoded);
      return JSON.stringify(parsed, null, 2);
    } catch (err) {
      return decoded;
    }
  }

  function initTrafficLogDrawer() {
    if (isInitialized) {
      return;
    }
    isInitialized = true;

    var drawer = document.getElementById('wtl-detail-drawer');
    var backdrop = document.getElementById('wtl-detail-backdrop');
    var content = document.getElementById('wtl-detail-content');
    var closeBtn = document.getElementById('wtl-detail-close');

    if (!drawer || !backdrop || !content) {
      return;
    }

    function closeDrawer() {
      drawer.hidden = true;
      backdrop.hidden = true;
      drawer.classList.remove('is-open');
      backdrop.classList.remove('is-open');
    }

    function openDrawer(payloadText) {
      content.textContent = payloadText || 'Entry payload is empty.';
      drawer.hidden = false;
      backdrop.hidden = false;
      drawer.classList.add('is-open');
      backdrop.classList.add('is-open');
    }

    document.addEventListener('click', function (event) {
      var openButton = event.target.closest('.wtl-view-entry');
      if (openButton) {
        var encoded = openButton.getAttribute('data-entry');
        var payload = decodeEntryPayload(encoded);
        openDrawer(payload || 'Unable to decode entry payload.');
        return;
      }

      if (event.target === backdrop) {
        closeDrawer();
      }
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', closeDrawer);
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeDrawer();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTrafficLogDrawer);
  } else {
    initTrafficLogDrawer();
  }
})();

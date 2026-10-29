/**
 * @file
 * Progressive behaviors shared by the Dresco theme.
 */

(function (Drupal, once) {
  Drupal.behaviors.frescoCopyUrl = {
    attach(context) {
      once('fresco-copy-url', '[data-fresco-copy-url]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const url = button.dataset.frescoCopyUrl || window.location.href;
          const status = document.getElementById(button.getAttribute('aria-describedby') || '');
          try {
            await navigator.clipboard.writeText(url);
            if (status) {
              status.textContent = Drupal.t('Public URL copied.');
            }
          }
          catch (error) {
            if (status) {
              status.textContent = Drupal.t('Copy failed. Use the open link instead.');
            }
          }
        });
      });
    },
  };
})(Drupal, once);

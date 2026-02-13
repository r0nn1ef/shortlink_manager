/**
 * @file
 * Shortlink clipboard copy functionality.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.shortlinkClipboard = {
    attach: function (context) {
      var buttons = once('shortlink-clipboard', '[data-shortlink-url]', context);
      buttons.forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          var url = this.getAttribute('data-shortlink-url');
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
              var original = button.textContent;
              button.textContent = Drupal.t('Copied!');
              button.classList.add('shortlink-copied');
              setTimeout(function () {
                button.textContent = original;
                button.classList.remove('shortlink-copied');
              }, 2000);
            });
          }
        });
      });
    }
  };

})(Drupal, once);

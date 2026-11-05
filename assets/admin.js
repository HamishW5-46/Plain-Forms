/* Shared actions for native WordPress lists. The builder/settings React app is loaded separately. */
(() => {
    'use strict';
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
    });
})();

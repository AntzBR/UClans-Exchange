"use strict";
(() => {
    const input = document.getElementById('v8_zip');
    const label = document.getElementById('fileLabel');
    const form = document.getElementById('exchangeForm');
    if (!input || !label || !form) return;
    input.addEventListener('change', () => {
        label.textContent = input.files?.[0]?.name || label.dataset.defaultLabel || 'Select the UltimateClans V8 ZIP';
    });
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (!button || button.disabled) return;
        const buttonText = button.querySelector('span');
        if (buttonText) buttonText.textContent = button.dataset.progressLabel || 'Converting…';
        button.disabled = true;
    });
})();

/*
 * Question form: show only the fields of the chosen question type, and add
 * option rows. The server validates everything again.
 */
(function () {
    'use strict';
    const form = document.getElementById('question-form');
    if (!form) return;
    const typeSelect = form.querySelector('[data-question-type]');
    const optionsBox = form.querySelector('#qf-options');

    function applyType() {
        const type = typeSelect.value;
        form.querySelectorAll('[data-for-type]').forEach(el => {
            const show = el.dataset.forType.split(' ').includes(type);
            el.hidden = !show;
            // Hidden fields must not be submitted or block submission.
            el.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = !show; });
            if (el.matches('input, select, textarea')) el.disabled = !show;
        });
    }

    form.querySelector('[data-add-option]')?.addEventListener('click', () => {
        const index = optionsBox.children.length;
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML =
            '<input type="radio" name="correct_option" value="' + index + '" aria-label="Option ' + (index + 1) + ' is correct" data-for-type="mcq">' +
            '<input type="checkbox" name="correct[]" value="' + index + '" aria-label="Option ' + (index + 1) + ' is correct" data-for-type="mcq_multi">' +
            '<input type="text" name="options[]" placeholder="Option ' + (index + 1) + '" maxlength="500" aria-label="Option ' + (index + 1) + '">';
        optionsBox.appendChild(row);
        applyType();
        row.querySelector('input[type="text"]').focus();
    });

    typeSelect.addEventListener('change', applyType);
    applyType();
})();

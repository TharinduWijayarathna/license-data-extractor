// Licence dashboard behaviour: drag & drop feedback, client-side upload checks,
// busy states, table search, dismissible alerts. The server re-validates everything.
(function () {
    'use strict';

    var ALLOWED = /\.(docx|pdf)$/i;

    function bytesLabel(size) {
        return size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(size / 1024)) + ' KB';
    }

    function initDropzone(zone) {
        var input = zone.querySelector('input[type=file]');
        var form = zone.closest('form');
        var list = form.querySelector('[data-picked]');
        var error = form.querySelector('[data-form-error]');
        var maxBytes = parseInt(zone.getAttribute('data-max-bytes'), 10) || 0;

        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function () { zone.setAttribute('data-drag', ''); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function () { zone.removeAttribute('data-drag'); });
        });

        function validate() {
            var problems = [];
            list.textContent = '';
            Array.prototype.forEach.call(input.files, function (file) {
                var bad = !ALLOWED.test(file.name) || (maxBytes && file.size > maxBytes);
                var item = document.createElement('li');
                item.className = 'flex items-center justify-between gap-3 text-[12px] ' +
                    (bad ? 'text-danger' : 'text-ink-2');
                var name = document.createElement('span');
                name.className = 'truncate';
                name.textContent = file.name;
                var size = document.createElement('span');
                size.className = 'shrink-0 font-mono text-[11px] text-faint';
                size.textContent = bytesLabel(file.size);
                item.appendChild(name);
                item.appendChild(size);
                list.appendChild(item);

                if (!ALLOWED.test(file.name)) {
                    problems.push(file.name + ': only .docx and .pdf files are accepted.');
                } else if (maxBytes && file.size > maxBytes) {
                    problems.push(file.name + ': larger than ' + bytesLabel(maxBytes) + '.');
                }
            });
            error.textContent = problems.join(' ');
            error.hidden = problems.length === 0;
            return problems.length === 0;
        }

        // Upload straight away once valid files are chosen or dropped
        input.addEventListener('change', function () {
            if (input.files.length > 0 && validate()) {
                form.requestSubmit();
            }
        });
        form.addEventListener('submit', function (event) {
            if (!validate()) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    }

    function initBusyForms() {
        var progress = document.querySelector('[data-progress]');
        document.querySelectorAll('form[data-busy]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type=submit]');
                if (button) {
                    button.disabled = true;
                    button.textContent = form.getAttribute('data-busy');
                }
                if (progress && form.hasAttribute('data-progress-trigger')) {
                    progress.hidden = false;
                }
            });
        });
    }

    function initFilter() {
        var input = document.querySelector('[data-filter]');
        // Each licence is rendered twice (table row for desktop, list item for phones)
        var records = document.querySelectorAll('[data-record]');
        if (!input || records.length === 0) {
            return;
        }
        var empty = document.querySelector('[data-no-match]');
        var count = document.querySelector('[data-count]');
        var total = records.length / 2;

        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            var shown = 0;
            Array.prototype.forEach.call(records, function (record) {
                var match = term === '' || record.textContent.toLowerCase().indexOf(term) !== -1;
                record.hidden = !match;
                shown += match ? 1 : 0;
            });
            shown = shown / 2;
            empty.hidden = shown !== 0;
            count.textContent = term === '' ? total + (total === 1 ? ' record' : ' records') : shown + ' of ' + total;
        });

        // "/" jumps to the filter, Escape clears it
        document.addEventListener('keydown', function (event) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
            if (event.key === '/' && !typing) {
                event.preventDefault();
                input.focus();
            } else if (event.key === 'Escape' && document.activeElement === input) {
                input.value = '';
                input.dispatchEvent(new Event('input'));
                input.blur();
            }
        });
    }

    function initDismiss() {
        document.querySelectorAll('[data-dismissible]').forEach(function (alert) {
            var button = alert.querySelector('[data-dismiss]');
            if (button) {
                button.addEventListener('click', function () { alert.remove(); });
            }
        });
    }

    document.querySelectorAll('[data-js-hidden]').forEach(function (el) { el.hidden = true; });
    document.querySelectorAll('[data-dropzone]').forEach(initDropzone);
    initBusyForms();
    initFilter();
    initDismiss();
})();

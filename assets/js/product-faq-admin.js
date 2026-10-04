(function () {
        'use strict';

        var box = document.getElementById('wutm_product_faq_box');

        if (!box) {
            return;
        }

        var tableBody = box.querySelector(
            '#wutm-product-faq-table tbody'
        );

        var addButton = box.querySelector(
            '#wutm-product-faq-add-row'
        );

        var template = box.querySelector(
            '#wutm-product-faq-row-template'
        );

        if (!tableBody || !addButton || !template) {
            return;
        }

        var editorCounter = 0;
        function saveSelection(editor) {
            var selection = window.getSelection();
            if (!selection || !selection.rangeCount) return;
            var range = selection.getRangeAt(0);
            if (editor.contains(range.commonAncestorContainer)) {
                editor.closest('.wutm-faq-editor').savedRange = range.cloneRange();
            }
        }
        function restoreSelection(editor) {
            var wrapper = editor.closest('.wutm-faq-editor');
            if (!wrapper || !wrapper.savedRange) return;
            editor.focus();
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(wrapper.savedRange);
        }
        function syncEditor(editor) {
            var wrapper = editor.closest('.wutm-faq-editor');
            var value = wrapper && wrapper.querySelector('.wutm-faq-answer-value');
            if (value) value.value = editor.innerHTML;
        }

        addButton.addEventListener('click', function () {
            var rowFragment = template.content.cloneNode(true);
            rowFragment.querySelectorAll('.wutm-faq-editable').forEach(function (editor) {
                editorCounter += 1;
                editor.id = 'wutm-product-faq-answer-new-' + Date.now() + '-' + editorCounter;
                editor.closest('.wutm-faq-editor').dataset.editorId = editor.id;
            });
            tableBody.appendChild(rowFragment);

            var rows = tableBody.querySelectorAll('tr');
            var lastRow = rows[rows.length - 1];

            if (lastRow) {
                var input = lastRow.querySelector(
                    'input[name="faq_question[]"]'
                );

                if (input) {
                    input.focus();
                }
            }
        });

        tableBody.addEventListener('keyup', function (event) {
            if (event.target.matches('.wutm-faq-editable')) saveSelection(event.target);
        });
        tableBody.addEventListener('mouseup', function (event) {
            if (event.target.matches('.wutm-faq-editable')) saveSelection(event.target);
        });
        tableBody.addEventListener('mousedown', function (event) {
            if (event.target.closest('.wutm-faq-format')) event.preventDefault();
        });

        tableBody.addEventListener('click', function (event) {
            var formatButton = event.target.closest('.wutm-faq-format');
            if (formatButton && tableBody.contains(formatButton)) {
                event.preventDefault();
                var editor = formatButton.closest('.wutm-faq-editor').querySelector('.wutm-faq-editable');
                restoreSelection(editor);
                document.execCommand(formatButton.dataset.command, false, null);
                syncEditor(editor);
                return;
            }

            var button = event.target.closest(
                '.wutm-product-faq-remove-row'
            );

            if (!button || !tableBody.contains(button)) {
                return;
            }

            var row = button.closest('tr');

            if (row) {
                var hasContent = row.querySelector('input[name="faq_question[]"]').value.trim() || row.querySelector('.wutm-faq-editable').textContent.trim();
                if (hasContent && !window.confirm('確定移除此問答？更新商品後才會儲存變更。')) return;
                row.remove();
            }
        });

        tableBody.addEventListener('input', function (event) {
            if (event.target.matches('.wutm-faq-editable')) syncEditor(event.target);
        });
        tableBody.addEventListener('change', function (event) {
            var wrapper = event.target.closest('.wutm-faq-editor');
            if (!wrapper) return;
            var editor = wrapper.querySelector('.wutm-faq-editable');
            restoreSelection(editor);
            if (event.target.matches('.wutm-faq-color')) {
                document.execCommand('foreColor', false, event.target.value);
            } else if (event.target.matches('.wutm-faq-size')) {
                document.execCommand('fontSize', false, event.target.value);
            }
            syncEditor(editor);
        });
        var productForm = box.closest('form');
        if (productForm) productForm.addEventListener('submit', function () {
            box.querySelectorAll('.wutm-faq-editable').forEach(syncEditor);
        });
    })();

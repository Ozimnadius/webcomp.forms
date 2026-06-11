(function () {
    'use strict';

    if (typeof window.fetch !== 'function') {
        return;
    }

    // Скрипт отвечает только за отправку формы webcomp:form: AJAX-сабмит,
    // рендер ошибок и сообщения об успехе. Машинерия всплывающего диалога
    // (открытие, ленивая загрузка, кэш) живет в /bitrix/js/webcomp.forms/dialog.js
    // и общается с формой только через всплывающие события.
    //
    // Обработчик делегирован на document: форма продолжает работать в любом
    // динамически вставленном DOM, включая фрагмент внутри диалога.

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-webcomp-form]');

        if (!form || form.dataset.ajax !== 'Y') {
            return;
        }

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }

        event.preventDefault();
        submitForm(form);
    });

    function submitForm(form) {
        if (form.dataset.submitting === 'Y') {
            return;
        }

        form.dataset.submitting = 'Y';

        var submitButton = form.querySelector('[data-webcomp-form-submit]');
        var originalButtonText = submitButton ? submitButton.textContent : '';

        clearState(form);
        setLoading(submitButton, true);

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                var contentType = response.headers.get('content-type') || '';

                if (contentType.indexOf('application/json') === -1) {
                    fallbackToNormalSubmit(form);
                    return null;
                }

                return response.json();
            })
            .then(function (result) {
                if (!result) {
                    return;
                }

                applyResult(form, result);
            })
            .catch(function () {
                fallbackToNormalSubmit(form);
            })
            .finally(function () {
                delete form.dataset.submitting;
                restoreButton(submitButton, originalButtonText);
            });
    }

    function clearState(form) {
        var root = form.closest('.webcomp-form') || form;
        var message = root.querySelector('[data-webcomp-form-message]');

        if (message) {
            message.hidden = true;
            message.className = 'webcomp-form__ajax-message';
            message.textContent = '';
        }

        root.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
        });

        root.querySelectorAll('[data-webcomp-form-ajax-error]').forEach(function (error) {
            error.remove();
        });
    }

    function applyResult(form, result) {
        var errors = result.errors || {};
        var success = result.success === true;

        if (success) {
            form.reset();
            showMessage(form, 'success', form.dataset.successMessage || 'Форма успешно отправлена.');
            notifyDialogInvalidate(form);
            return;
        }

        Object.keys(errors).forEach(function (code) {
            if (code === 'SYSTEM') {
                return;
            }

            showFieldError(form, code, errors[code]);
        });

        showMessage(
            form,
            'danger',
            errors.SYSTEM || form.dataset.errorMessage || 'Проверьте правильность заполнения формы.'
        );
    }

    // После успешной отправки форма сообщает наверх, что ее DOM отработан.
    // Если форма открыта в диалоге webcomp.forms, тот сбросит кэш фрагмента
    // и при следующем открытии загрузит чистую форму; инлайн-форма диспатчит
    // событие в пустоту, и это безопасно.
    function notifyDialogInvalidate(form) {
        form.dispatchEvent(new CustomEvent('webcomp:dialog:invalidate', {
            bubbles: true
        }));
    }

    function showMessage(form, type, text) {
        var root = form.closest('.webcomp-form') || form;
        var message = root.querySelector('[data-webcomp-form-message]');

        if (!message) {
            message = document.createElement('div');
            message.setAttribute('data-webcomp-form-message', '');
            root.insertBefore(message, root.firstChild);
        }

        message.hidden = false;
        message.className = 'webcomp-form__ajax-message alert alert-' + type;
        message.setAttribute('role', 'alert');
        message.textContent = text;
    }

    function showFieldError(form, code, text) {
        var field = form.querySelector('[name="' + code + '"], [name="' + code + '[]"]');

        if (!field) {
            return;
        }

        var container = field.closest('.mb-3') || field.closest('fieldset') || field.parentNode;
        var error = document.createElement('div');

        field.classList.add('is-invalid');

        error.className = 'invalid-feedback d-block';
        error.setAttribute('data-webcomp-form-ajax-error', '');
        error.textContent = text;
        container.appendChild(error);
    }

    function setLoading(button, isLoading) {
        if (!button) {
            return;
        }

        button.disabled = isLoading;

        if (isLoading) {
            button.textContent = 'Отправка...';
        }
    }

    function restoreButton(button, text) {
        if (!button) {
            return;
        }

        button.disabled = false;
        button.textContent = text;
    }

    function fallbackToNormalSubmit(form) {
        form.dataset.ajax = 'N';
        form.submit();
    }
})();

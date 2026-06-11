(function () {
    'use strict';

    // Универсальный лоадер всплывающих диалогов webcomp.forms.
    //
    // Контракт для любого компонента:
    // - триггер: элемент с data-webcomp-dialog-url (откуда грузить HTML)
    //   и необязательным data-webcomp-dialog-key (ключ кэша; триггеры
    //   с одним ключом делят один диалог);
    // - события на элементе диалога (всплывают до document):
    //   webcomp:dialog:opened, webcomp:dialog:loaded, webcomp:dialog:error,
    //   webcomp:dialog:closed;
    // - контент может диспатчить всплывающее событие webcomp:dialog:invalidate,
    //   чтобы при следующем открытии фрагмент загрузился заново.
    //
    // Библиотека ничего не знает о содержимом фрагмента: валидация, отправка
    // и рендер ответа остаются ответственностью скриптов самого контента.

    if (typeof window.fetch !== 'function' || typeof window.HTMLDialogElement === 'undefined') {
        return;
    }

    var dialogs = {};

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-webcomp-dialog-url]');

        if (opener) {
            event.preventDefault();
            openDialog(opener);
            return;
        }

        var closer = event.target.closest('[data-webcomp-dialog-close]');

        if (closer) {
            var dialog = closer.closest('dialog');

            if (dialog) {
                dialog.close();
            }

            return;
        }

        // Клик по самому dialog мимо контента означает клик по backdrop.
        if (event.target instanceof HTMLDialogElement && event.target.hasAttribute('data-webcomp-dialog')) {
            event.target.close();
        }
    });

    document.addEventListener('webcomp:dialog:invalidate', function (event) {
        var dialog = event.target instanceof Element
            ? event.target.closest('dialog[data-webcomp-dialog]')
            : null;

        if (dialog) {
            delete dialog.dataset.loaded;
        }
    });

    function openDialog(opener) {
        var url = opener.getAttribute('data-webcomp-dialog-url');

        if (!url) {
            return;
        }

        var key = opener.getAttribute('data-webcomp-dialog-key') || url;
        var dialog = dialogs[key] || createDialog(key);

        if (!dialog.open) {
            dialog.showModal();
            emit(dialog, 'webcomp:dialog:opened');
        }

        if (dialog.dataset.loaded === 'Y' || dialog.dataset.loading === 'Y') {
            return;
        }

        loadContent(dialog, url);
    }

    function createDialog(key) {
        var dialog = document.createElement('dialog');
        dialog.className = 'webcomp-dialog';
        dialog.setAttribute('data-webcomp-dialog', '');

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'webcomp-dialog__close';
        closeButton.setAttribute('data-webcomp-dialog-close', '');
        closeButton.setAttribute('aria-label', 'Закрыть');
        closeButton.innerHTML = '&times;';

        var body = document.createElement('div');
        body.className = 'webcomp-dialog__body';
        body.setAttribute('data-webcomp-dialog-body', '');

        dialog.appendChild(closeButton);
        dialog.appendChild(body);
        document.body.appendChild(dialog);

        dialog.addEventListener('close', function () {
            emit(dialog, 'webcomp:dialog:closed');
        });

        dialogs[key] = dialog;

        return dialog;
    }

    function loadContent(dialog, url) {
        var body = dialog.querySelector('[data-webcomp-dialog-body]');

        if (!body) {
            return;
        }

        dialog.dataset.loading = 'Y';
        body.innerHTML = '<div class="webcomp-dialog__loader">Загрузка...</div>';

        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                dialog.dataset.loaded = 'Y';
                emit(dialog, 'webcomp:dialog:loaded');
            })
            .catch(function () {
                body.innerHTML = '<div class="alert alert-danger webcomp-dialog__error" role="alert">Не удалось загрузить содержимое. Попробуйте позже.</div>';
                emit(dialog, 'webcomp:dialog:error');
            })
            .finally(function () {
                delete dialog.dataset.loading;
            });
    }

    function emit(target, name) {
        target.dispatchEvent(new CustomEvent(name, { bubbles: true }));
    }
})();

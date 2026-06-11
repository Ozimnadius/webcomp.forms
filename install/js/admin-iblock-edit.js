(function (window, document) {
    'use strict';

    var config = window.WebcompFormsAdminIblockEdit || {};
    var allowedType = config.allowedType;
    var selector = 'select[name^="IB_PROPERTY_"][name$="_PROPERTY_TYPE"]';

    if (!allowedType) {
        return;
    }

    function toArray(collection) {
        return Array.prototype.slice.call(collection || []);
    }

    function restrictSelect(select) {
        var allowedOption = toArray(select.options).filter(function (option) {
            return option.value === allowedType;
        })[0];

        if (!allowedOption) {
            return;
        }

        toArray(select.options).forEach(function (option) {
            var isAllowed = option.value === allowedType;

            option.disabled = !isAllowed;
            option.hidden = !isAllowed;
            option.style.display = isAllowed ? '' : 'none';
        });

        if (select.value !== allowedType) {
            select.value = allowedType;
            allowedOption.selected = true;
        }
    }

    function restrictAll(root) {
        toArray((root || document).querySelectorAll(selector)).forEach(restrictSelect);
    }

    function watchPropertyTable() {
        if (!window.MutationObserver) {
            document.addEventListener('click', function () {
                window.setTimeout(function () {
                    restrictAll(document);
                }, 0);
            }, true);

            return;
        }

        var target = document.getElementById('ib_prop_list') || document.body;
        var observer = new MutationObserver(function () {
            restrictAll(document);
        });

        observer.observe(target, {
            childList: true,
            subtree: true
        });
    }

    function init() {
        restrictAll(document);
        watchPropertyTable();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);

(function () {
    'use strict';
    var environment = document.querySelector('[data-hachitech-field="environment"]');
    if (environment) {
        environment.addEventListener('change', function () {
            document.querySelectorAll('[data-hachitech-environment]').forEach(function (group) {
                group.hidden = group.dataset.hachitechEnvironment !== environment.value;
            });
        });
    }
}());

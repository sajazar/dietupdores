(function () {
    'use strict';
    document.querySelectorAll('.dietup-form').forEach(function (form) {
        var workplace = form.querySelector('[data-dietup-workplace]');
        var other = form.querySelector('[data-dietup-other]');
        var otherInput = other ? other.querySelector('input') : null;
        var cert = form.querySelector('[data-dietup-certificate]');
        var certInput = cert ? cert.querySelector('input') : null;
        function refresh() {
            var showOther = workplace && workplace.value === 'سایر';
            if (other) other.hidden = !showOther;
            if (otherInput) otherInput.required = !!showOther;
            var selected = form.querySelector('input[name="package_key"]:checked');
            var needsCert = !!(selected && selected.dataset.certificate === '1');
            if (cert) cert.hidden = !needsCert;
            if (certInput) certInput.required = needsCert;
        }
        if (workplace) workplace.addEventListener('change', refresh);
        form.querySelectorAll('input[name="package_key"]').forEach(function (input) {
            input.addEventListener('change', refresh);
        });
        refresh();
    });
}());

(function () {
    'use strict';
    document.querySelectorAll('.dietup-form').forEach(function (form) {
        var workplace = form.querySelector('[data-dietup-workplace]');
        var other = form.querySelector('[data-dietup-other]');
        var otherInput = other ? other.querySelector('input') : null;
        var cert = form.querySelector('[data-dietup-certificate]');
        var certInput = cert ? cert.querySelector('input') : null;
        var profession = form.querySelector('select[name="profession"]');
        var specialist = form.querySelector('[data-dietup-specialist]');
        var specialistSelect = specialist ? specialist.querySelector('select') : null;
        var generalSpecialty = form.querySelector('[data-dietup-specialty-text]');
        var generalSpecialtyInput = generalSpecialty ? generalSpecialty.querySelector('input') : null;
        var specialistOther = form.querySelector('[data-dietup-specialty-other]');
        var specialistOtherInput = specialistOther ? specialistOther.querySelector('input') : null;

        function refresh() {
            var showOther = workplace && workplace.value === 'سایر';
            if (other) other.hidden = !showOther;
            if (otherInput) otherInput.required = !!showOther;

            var selected = form.querySelector('input[name="package_key"]:checked');
            var needsCert = !!(selected && selected.dataset.certificate === '1');
            if (cert) cert.hidden = !needsCert;
            if (certInput) certInput.required = needsCert;

            var isSpecialist = profession && profession.value === 'پزشک متخصص';
            if (specialist) specialist.hidden = !isSpecialist;
            if (specialistSelect) specialistSelect.required = !!isSpecialist;
            if (generalSpecialty) generalSpecialty.hidden = !profession || !profession.value || isSpecialist;
            if (generalSpecialtyInput) generalSpecialtyInput.required = !!profession && !isSpecialist && profession.value !== '';
            var needsOtherSpecialty = isSpecialist && specialistSelect && specialistSelect.value === 'سایر تخصص‌ها';
            if (specialistOther) specialistOther.hidden = !needsOtherSpecialty;
            if (specialistOtherInput) specialistOtherInput.required = !!needsOtherSpecialty;
        }

        if (workplace) workplace.addEventListener('change', refresh);
        if (profession) profession.addEventListener('change', refresh);
        if (specialistSelect) specialistSelect.addEventListener('change', refresh);
        form.querySelectorAll('input[name="package_key"]').forEach(function (input) {
            input.addEventListener('change', refresh);
        });
        refresh();
    });
}());

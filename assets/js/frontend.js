(function () {
    'use strict';

    function formatToman(value) {
        return Math.max(0, Number(value) || 0).toLocaleString('fa-IR') + ' تومان';
    }

    function initDietUpForms() {
    document.querySelectorAll('.dietup-form').forEach(function (form) {
        var workplace = form.querySelector('[data-dietup-workplace]');
        var other = form.querySelector('[data-dietup-other]');
        var otherInput = other ? other.querySelector('input') : null;
        var cert = form.querySelector('[data-dietup-certificate]');
        var certInput = cert ? cert.querySelector('input') : null;
        var profession = form.querySelector('select[name="profession"]');
        var specialist = form.querySelector('[data-dietup-specialist]');
        var specialistSelect = specialist ? specialist.querySelector('select') : null;
        var specialistOther = form.querySelector('[data-dietup-specialty-other]');
        var specialistOtherInput = specialistOther ? specialistOther.querySelector('input') : null;

        var couponInput = form.querySelector('[name="coupon_code"]');
        var applyButton = form.querySelector('[data-dietup-apply-coupon]');
        var couponMessage = form.querySelector('[data-dietup-coupon-message]');
        var baseOutput = form.querySelector('[data-dietup-base-price]');
        var discountOutput = form.querySelector('[data-dietup-discount]');
        var finalOutput = form.querySelector('[data-dietup-final-price]');
        var appliedCoupon = null;
        var couponRequest = false;
        var allowSubmitOnce = false;

        function selectedPackage() {
            return form.querySelector('input[name="package_key"]:checked');
        }

        function showBasePrice() {
            var selected = selectedPackage();
            var base = selected ? Number(selected.dataset.price || 0) : 0;
            if (baseOutput) baseOutput.textContent = selected ? formatToman(base) : '—';
            if (discountOutput) discountOutput.textContent = formatToman(0);
            if (finalOutput) finalOutput.textContent = selected ? formatToman(base) : 'ابتدا پکیج را انتخاب کنید';
        }

        function refresh() {
            var showOther = workplace && workplace.value === 'سایر';
            if (other) other.hidden = !showOther;
            if (otherInput) otherInput.required = !!showOther;

            var selected = selectedPackage();
            var needsCert = !!(selected && selected.dataset.certificate === '1');
            if (cert) cert.hidden = !needsCert;
            if (certInput) certInput.required = needsCert;

            var isSpecialist = profession && profession.value === 'پزشک متخصص';
            if (specialist) specialist.hidden = !isSpecialist;
            if (specialistSelect) specialistSelect.required = !!isSpecialist;
            var needsOtherSpecialty = isSpecialist && specialistSelect && specialistSelect.value === 'سایر تخصص‌ها';
            if (specialistOther) specialistOther.hidden = !needsOtherSpecialty;
            if (specialistOtherInput) specialistOtherInput.required = !!needsOtherSpecialty;
        }

        function setMessage(message, isError) {
            if (!couponMessage) return;
            couponMessage.textContent = message;
            couponMessage.classList.toggle('is-error', !!isError);
            couponMessage.classList.toggle('is-success', !isError);
        }

        function applyCoupon() {
            var selected = selectedPackage();
            var code = couponInput ? couponInput.value.trim() : '';
            if (!selected) {
                setMessage('ابتدا یک پکیج انتخاب کنید.', true);
                return Promise.resolve(false);
            }
            if (!code) {
                appliedCoupon = null;
                showBasePrice();
                setMessage('کد تخفیف وارد نشده است؛ قیمت اصلی محاسبه شد.', false);
                return Promise.resolve(true);
            }
            if (couponRequest) return Promise.resolve(false);
            couponRequest = true;
            if (applyButton) {
                applyButton.disabled = true;
                applyButton.textContent = 'در حال بررسی...';
            }
            var packageKey = selected.value;
            var requestBody = new URLSearchParams();
            requestBody.set('action', 'dietup_validate_coupon');
            requestBody.set('nonce', form.dataset.couponNonce || '');
            requestBody.set('package_key', packageKey);
            requestBody.set('coupon_code', code);

            return fetch(form.dataset.couponAjax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: requestBody.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                var current = selectedPackage();
                if (!current || current.value !== packageKey || (couponInput && couponInput.value.trim() !== code)) return false;
                if (!result || !result.success || !result.data) {
                    appliedCoupon = null;
                    showBasePrice();
                    setMessage(result && result.data && result.data.message ? result.data.message : 'بررسی کد تخفیف انجام نشد؛ دوباره تلاش کنید.', true);
                    return false;
                }
                appliedCoupon = {code: code, packageKey: packageKey};
                if (baseOutput) baseOutput.textContent = formatToman(result.data.base);
                if (discountOutput) discountOutput.textContent = formatToman(result.data.discount);
                if (finalOutput) finalOutput.textContent = formatToman(result.data.final);
                setMessage(result.data.message + (result.data.percent ? ' (' + result.data.percent.toLocaleString('fa-IR') + '٪)' : ''), false);
                return true;
            }).catch(function () {
                appliedCoupon = null;
                showBasePrice();
                setMessage('ارتباط برای بررسی کد تخفیف برقرار نشد. دوباره تلاش کنید.', true);
                return false;
            }).finally(function () {
                couponRequest = false;
                if (applyButton) {
                    applyButton.disabled = false;
                    applyButton.textContent = 'اعمال کد تخفیف';
                }
            });
        }

        if (workplace) workplace.addEventListener('change', refresh);
        if (profession) profession.addEventListener('change', refresh);
        if (specialistSelect) specialistSelect.addEventListener('change', refresh);
        form.querySelectorAll('input[name="package_key"]').forEach(function (input) {
            input.addEventListener('change', function () {
                appliedCoupon = null;
                showBasePrice();
                if (couponInput && couponInput.value.trim()) setMessage('پکیج تغییر کرد؛ برای محاسبه دوباره تخفیف، کد را مجدداً اعمال کنید.', false);
                refresh();
            });
        });
        if (applyButton) applyButton.addEventListener('click', applyCoupon);
        if (couponInput) couponInput.addEventListener('input', function () {
            if (appliedCoupon && couponInput.value.trim() !== appliedCoupon.code) {
                appliedCoupon = null;
                showBasePrice();
                setMessage('برای اعمال کد جدید، دکمه اعمال کد تخفیف را بزنید.', false);
            }
        });

        form.addEventListener('submit', function (event) {
            if (allowSubmitOnce) return;
            var code = couponInput ? couponInput.value.trim() : '';
            if (code && (!appliedCoupon || appliedCoupon.code !== code || !selectedPackage() || appliedCoupon.packageKey !== selectedPackage().value)) {
                event.preventDefault();
                applyCoupon().then(function (ok) {
                    if (ok) {
                        allowSubmitOnce = true;
                        if (typeof form.requestSubmit === 'function') form.requestSubmit();
                        else form.submit();
                    }
                });
            }
        });

        refresh();
        showBasePrice();
    });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDietUpForms, {once: true});
    } else {
        initDietUpForms();
    }
}());

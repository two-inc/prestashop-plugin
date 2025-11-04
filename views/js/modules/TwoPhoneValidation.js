/**
 * Two Phone Validation - intl-tel-input with live feedback and normalization
 * - Address step only (checkout)
 * - Auto-initial country from shipping country select; fallback to IP if available
 * - Live validity messages, auto-format as typing, normalize to E.164 on blur/submit
 */
class TwoPhoneValidation {
    constructor(config) {
        this.config = {
            phoneSelector: "input[name='phone']",
            countrySelector: "select[name='id_country']",
            utilsUrl: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js",
            scriptUrl: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/intlTelInput.min.js",
            cssUrl: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.min.css",
            preferredCountries: ["gb", "es", "no"],
            ...config
        };

        this.phoneInput = null;
        this.iti = null;
        this.errorEl = null;
        this.isInitialized = false;
        this.lastCountryIso2 = null;

        this.init();
        this.setupDomObservers();
        this.setupPrestashopHooks();
    }

    findCountrySelect() {
        const selectors = [
            this.config.countrySelector,
            "select[name='country']",
            "#id_country",
            ".js-country",
            "select.country"
        ].filter(Boolean);
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el) return el;
        }
        return null;
    }

    extractIsoFromText(text) {
        if (!text) return null;
        const map = {
            'united kingdom': 'GB', 'great britain': 'GB', 'uk': 'GB', 'england': 'GB',
            'spain': 'ES', 'españa': 'ES', 'espagne': 'ES',
            'norway': 'NO', 'norge': 'NO',
            'france': 'FR', 'francia': 'FR',
            'germany': 'DE', 'deutschland': 'DE', 'alemania': 'DE',
            'netherlands': 'NL', 'holland': 'NL', 'países bajos': 'NL',
            'italy': 'IT', 'italia': 'IT'
        };
        const key = String(text).toLowerCase().trim();
        return map[key] || null;
    }

    init() {
        // ENHANCED: More flexible phone field detection for different themes
        const phoneFieldSelectors = [
            this.config.phoneSelector,
            "input[name='phone']",
            "input[name='phone_mobile']", 
            "#phone", 
            "#phone_mobile",
            ".phone-field input",
            "input[type='tel']"
        ];
        
        let phoneField = null;
        for (const selector of phoneFieldSelectors) {
            phoneField = document.querySelector(selector);
            if (phoneField) break;
        }
        
        if (!phoneField) {
            // Retry after DOM might be loaded by AJAX (some themes load forms dynamically)
            setTimeout(() => {
                for (const selector of phoneFieldSelectors) {
                    phoneField = document.querySelector(selector);
                    if (phoneField) break;
                }
                if (phoneField && !phoneField.hasAttribute('data-intl-tel-input-id')) {
                    this.lazyLoadAssets().then(() => {
                        this.initializePlugin(phoneField);
                    }).catch(() => {});
                }
            }, 500);
            return;
        }
        
        // Guard: already initialized by intl-tel-input
        if (phoneField.hasAttribute('data-intl-tel-input-id')) return;

        this.lazyLoadAssets().then(() => {
            this.initializePlugin(phoneField);
        }).catch((error) => {
            console.warn('Two Phone Validation: Failed to load assets:', error);
        });
    }

    lazyLoadAssets() {
        // CSS
        if (this.config.cssUrl && !document.querySelector('link[data-two-iti="1"]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = this.config.cssUrl;
            link.setAttribute('data-two-iti', '1');
            document.head.appendChild(link);
        }

        // Minimal inline CSS fallback to ensure hidden country list
        if (!document.querySelector('style[data-two-iti-fallback="1"]')) {
            const style = document.createElement('style');
            style.setAttribute('data-two-iti-fallback', '1');
            style.textContent = [
                '.iti__country-list{position:absolute;z-index:10000;max-height:240px;overflow:auto;background:#fff;border:1px solid #ccc;}',
                '.iti__country-list.iti__hide{display:none !important;}',
                '.iti__flag-container{cursor:pointer;}',
            ].join('');
            document.head.appendChild(style);
        }

        // Script
        if (typeof window.intlTelInput === 'function') {
            return Promise.resolve();
        }
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = this.config.scriptUrl;
            s.async = true;
            s.setAttribute('data-two-iti', '1');
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Failed to load intl-tel-input'));
            document.head.appendChild(s);
        });
    }

    getSelectedCountryIso2() {
        const select = this.findCountrySelect();
        // 1) Prefer mapping from id_country using backend-provided map (most reliable)
        if (select && window.twopayment && window.twopayment.countries) {
            const id = String(select.value || '').trim();
            const mapIso = window.twopayment.countries[id];
            if (mapIso && typeof mapIso === 'string') return mapIso.toLowerCase();
        }
        // 2) Data attributes on option
        if (select) {
            const opt = select.options[select.selectedIndex];
            const isoAttr = opt && (opt.getAttribute('data-iso-code') || opt.getAttribute('data-iso'));
            if (isoAttr) return isoAttr.toLowerCase();
            // 3) Parse from visible text
            const parsed = this.extractIsoFromText(opt && opt.textContent);
            if (parsed) return parsed.toLowerCase();
        }
        // 4) Session remember (last known)
        try {
            const saved = sessionStorage.getItem('two_phone_country_iso2');
            if (saved && typeof saved === 'string') return saved.toLowerCase();
        } catch (e) {}
        // 5) Browser locale fallback
        const lang = navigator.language || navigator.userLanguage;
        if (lang && lang.includes('-')) return lang.split('-')[1].toLowerCase();
        return null;
    }

    initializePlugin(targetField) {
        if (this.isInitialized || typeof window.intlTelInput !== 'function') return;
        const phoneField = targetField || document.querySelector(this.config.phoneSelector);
        if (!phoneField) return;
        if (phoneField.hasAttribute('data-intl-tel-input-id')) return;

        const initialCountry = this.getSelectedCountryIso2() || 'auto';
        const geoIpLookup = (callback) => {
            try {
                // optional: simple IP-based lookup; fallback to GB
                fetch('https://ipapi.co/country/')
                    .then(r => r.ok ? r.text() : 'GB')
                    .then(code => callback((code || 'GB').toLowerCase()))
                    .catch(() => callback('gb'));
            } catch (e) { callback('gb'); }
        };

        try {
            this.iti = window.intlTelInput(phoneField, {
                initialCountry,
                preferredCountries: this.config.preferredCountries,
                utilsScript: this.config.utilsUrl,
                autoPlaceholder: 'aggressive',
                formatOnDisplay: true,
                geoIpLookup: initialCountry === 'auto' ? geoIpLookup : null,
                // Allow users to type national numbers without '+' code
                nationalMode: true,
                // Do not show separate dial code element to avoid extra UI
                separateDialCode: false
            });
        } catch (e) {
            return;
        }

        this.phoneInput = phoneField;
        this.isInitialized = true;
        this.createErrorEl();

        // Force selected country from form immediately after init
        const iso2Now = this.getSelectedCountryIso2();
        if (iso2Now && this.iti && this.iti.setCountry) {
            try { this.iti.setCountry(iso2Now); } catch (e) {}
            try { sessionStorage.setItem('two_phone_country_iso2', iso2Now || ''); } catch (e) {}
        }

        // Bind events
        const countrySelect = this.findCountrySelect();
        if (countrySelect) {
            countrySelect.addEventListener('change', () => {
                const iso2 = this.getSelectedCountryIso2();
                if (iso2 && this.iti && this.iti.setCountry) this.iti.setCountry(iso2);
                // Remember selection to prevent reversion after DOM refresh
                try { sessionStorage.setItem('two_phone_country_iso2', iso2 || ''); } catch (e) {}
                this.clearError();
                // Re-validate and reformat under the new country rules
                this.onInputChanged();
                this.normalizeAndValidate(false);
            });
        }
        this.phoneInput.addEventListener('input', () => this.onInputChanged());
        this.phoneInput.addEventListener('blur', () => this.onBlur());

        const form = this.phoneInput.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                if (!this.normalizeAndValidate(true)) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.phoneInput.focus();
                    return false;
                }
                return true;
            }, true);
        }
    }

    setupPrestashopHooks() {
        try {
            if (typeof prestashop !== 'undefined' && prestashop.on) {
                prestashop.on('updatedAddressForm', () => {
                    // Attempt to (re)initialize on the new phone field
                    this.isInitialized = false;
                    this.init();
                });
            }
        } catch (e) {}
    }

    setupDomObservers() {
        if (window.__TwoPhoneValidationObserverAttached) return;
        try {
            const observer = new MutationObserver((mutations) => {
                // Debounce scans
                clearTimeout(this._scanTimeout);
                this._scanTimeout = setTimeout(() => {
                    // ENHANCED: Use same flexible phone field detection as init()
                    const phoneFieldSelectors = [
                        this.config.phoneSelector,
                        "input[name='phone']",
                        "input[name='phone_mobile']", 
                        "#phone", 
                        "#phone_mobile",
                        ".phone-field input",
                        "input[type='tel']"
                    ];
                    
                    let field = null;
                    for (const selector of phoneFieldSelectors) {
                        field = document.querySelector(selector);
                        if (field && !field.hasAttribute('data-intl-tel-input-id')) break;
                        field = null; // Reset if already initialized
                    }
                    
                    if (field) {
                        if (typeof window.intlTelInput === 'function') {
                            this.isInitialized = false;
                            this.initializePlugin(field);
                        } else {
                            // Ensure assets then init
                            this.lazyLoadAssets().then(() => {
                                this.isInitialized = false;
                                this.initializePlugin(field);
                            }).catch((error) => {
                                console.warn('Two Phone Validation: Failed to load assets in observer:', error);
                            });
                        }
                    }
                }, 50);
            });
            observer.observe(document.body, { childList: true, subtree: true });
            window.__TwoPhoneValidationObserverAttached = true;
        } catch (e) {
            console.warn('Two Phone Validation: Failed to setup DOM observer:', e);
        }
    }

    createErrorEl() {
        const hint = document.createElement('div');
        hint.className = 'two-phone-hint';
        hint.style.marginTop = '4px';
        hint.style.fontSize = '0.875rem';
        hint.style.color = '#dc3545';
        hint.style.display = 'none';
        this.phoneInput.parentNode.insertBefore(hint, this.phoneInput.nextSibling);
        this.errorEl = hint;
    }

    onInputChanged() {
        if (!this.iti) return;
        this.clearError();
        // show live feedback but do not block yet
        try {
            const num = this.phoneInput.value.trim();
            if (num.length === 0) return;
            const isPossible = this.iti.isPossibleNumber();
            const isValid = this.iti.isValidNumber();
            if (!isPossible || !isValid) {
                const err = this.iti.getValidationError();
                const msg = this.mapError(err);
                this.showError(msg);
            }
        } catch (e) {}
    }

    onBlur() {
        // Validate but keep national format in the field for UX
        this.normalizeAndValidate(false);
    }

    normalizeAndValidate(forSubmit = false) {
        if (!this.iti) return true;
        const value = this.phoneInput.value ? this.phoneInput.value.trim() : '';
        if (value.length === 0) {
            this.clearError();
            return true;
        }
        try {
            if (!this.iti.isValidNumber()) {
                const err = this.iti.getValidationError();
                this.showError(this.mapError(err));
                return false;
            }
            // Country is enforced by intl-tel-input via selected country context
            if (forSubmit) {
                // Submit normalized as E.164 for backend/API
                const e164 = this.iti.getNumber();
                if (e164 && typeof e164 === 'string') this.phoneInput.value = e164;
            } else if (window.intlTelInputUtils && this.iti.getNumber) {
                // Keep/format display as NATIONAL for UX
                try {
                    const nat = this.iti.getNumber(window.intlTelInputUtils.numberFormat.NATIONAL);
                    if (nat && typeof nat === 'string') this.phoneInput.value = nat;
                } catch (e) {}
            }
            this.clearError();
            return true;
        } catch (e) {
            return true; // fail-open
        }
    }

    extractDialFromE164(e164) {
        if (typeof e164 !== 'string') return null;
        const m = e164.match(/^\+(\d{1,4})/);
        return m ? m[1] : null;
    }

    selectedDialCode() {
        try {
            const data = this.iti && this.iti.getSelectedCountryData ? this.iti.getSelectedCountryData() : null;
            if (data && data.dialCode) return String(data.dialCode);
        } catch (e) {}
        return null;
    }

    mapError(code) {
        const T = (key, fallback) => {
            try {
                return (window.twopayment && window.twopayment.phone_i18n && window.twopayment.phone_i18n[key]) || fallback;
            } catch (e) { return fallback; }
        };
        const errors = {
            0: T('invalid_number', 'Invalid phone number'),
            1: T('invalid_country_code', 'Invalid country code'),
            2: T('too_short', 'Too short'),
            3: T('too_long', 'Too long'),
            4: T('invalid_number', 'Invalid phone number')
        };
        return errors.hasOwnProperty(code) ? errors[code] : T('invalid_number', 'Invalid phone number');
    }

    showError(msg) {
        if (!this.errorEl) return;
        this.errorEl.textContent = msg;
        this.errorEl.style.display = 'block';
        this.phoneInput.classList.add('is-invalid');
    }

    clearError() {
        if (this.errorEl) {
            this.errorEl.textContent = '';
            this.errorEl.style.display = 'none';
        }
        this.phoneInput && this.phoneInput.classList.remove('is-invalid');
    }
}

window.TwoPhoneValidation = TwoPhoneValidation;



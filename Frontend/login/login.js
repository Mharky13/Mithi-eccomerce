/* ==========================================================================
   Mithi – Login / Sign Up page logic
   Request + validation logic is taken from login_1.js (the version that works
   with the backend). UI wiring matches login.html / login.css.
   ========================================================================== */

/* --------------------------------------------------------------------------
   Tailwind CDN configuration
   Must run right after the Tailwind CDN <script>, so this file is loaded in
   <head>. All DOM work below waits for DOMContentLoaded.
   -------------------------------------------------------------------------- */
tailwind.config = {
    theme: {
        extend: {
            colors: {
                mithi: {
                    ink: '#475467',
                    slate: '#3A4D5C',
                    leaf: '#D9891A',
                    cream: '#F4EEE3',
                    toast: '#FFAE46',
                },
            },
            fontFamily: {
                inter: ['Inter', 'sans-serif'],
                montserrat: ['Montserrat', 'sans-serif'],
            },
            boxShadow: {
                'card-glow': '0 30px 70px -10px rgba(235, 110, 40, 0.38), 0 15px 35px -10px rgba(0, 0, 0, 0.12)',
            },
        },
    },
};

(() => {
    'use strict';

    /* ----------------------------------------------------------------------
       Constants
       ---------------------------------------------------------------------- */
    const ENDPOINTS = {
        login: '../../Backend/login.php',
        signup: '../../Backend/mithi.php',
    };

    const HOME_PAGE = '../home_page/index.html';

    const TOAST_DURATION_MS = 3500;
    const MIN_PASSWORD_LENGTH = 8;

    const NAME_REGEX = /^[a-zA-Z\s]+$/;
    const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    const TOAST_ICONS = {
        success: 'fa-check-circle text-white',
        error: 'fa-exclamation-circle text-white',
        info: 'fa-info-circle text-white',
    };

    /* ----------------------------------------------------------------------
       Helpers
       ---------------------------------------------------------------------- */
    const byId = (id) => document.getElementById(id);
    const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** Turn a form's submit button into a loading / idle state. */
    function setButtonLoading({ button, label, spinner }, isLoading, loadingText, idleText) {
        button.disabled = isLoading;
        button.setAttribute('aria-busy', String(isLoading));
        label.textContent = isLoading ? loadingText : idleText;
        spinner.classList.toggle('hidden', !isLoading);
    }

    /* ----------------------------------------------------------------------
       Toast notifications
       ---------------------------------------------------------------------- */
    let toastTimeout;

    function dismissToast() {
        const toast = byId('toast');
        toast.classList.add('hidden');
        toast.classList.remove('flex');
    }

    function showToast(message, type = 'success') {
        const toast = byId('toast');
        const iconClasses = TOAST_ICONS[type] || TOAST_ICONS.info;

        byId('toast-message').textContent = message;
        byId('toast-icon').innerHTML = `<i class="fas ${iconClasses} text-lg"></i>`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        toast.classList.remove('hidden', 'flex'); // re-trigger entrance animation
        void toast.offsetWidth;
        toast.classList.add('flex');

        clearTimeout(toastTimeout);
        toastTimeout = setTimeout(dismissToast, TOAST_DURATION_MS);
    }

    /* ----------------------------------------------------------------------
       View switching & password visibility
       ---------------------------------------------------------------------- */
    let currentMode = 'login';

    function toggleAuthMode(mode) {
        if (mode === currentMode) return;
        currentMode = mode;

        byId('authCard').dataset.mode = mode; // crossfades banner art via CSS
        document.title = mode === 'signup' ? 'Mithi – Sign up' : 'Mithi – Login';

        document.querySelectorAll('[data-mode-panel]').forEach((panel) => {
            const isActive = panel.dataset.modePanel === mode;
            panel.classList.toggle('hidden', !isActive);
            panel.classList.remove('is-entering');
            if (isActive && !prefersReducedMotion()) {
                void panel.offsetWidth;
                panel.classList.add('is-entering');
            }
        });

        const heading = byId(mode === 'signup' ? 'signupSection' : 'loginSection').querySelector('h1');
        heading.focus({ preventScroll: true });
        if (heading.getBoundingClientRect().top < 0) {
            heading.scrollIntoView({ block: 'start', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        }
    }

    function togglePasswordVisibility(inputId, button) {
        const input = byId(inputId);
        const icon = button.querySelector('i');
        const reveal = input.type === 'password';

        input.type = reveal ? 'text' : 'password';
        icon.classList.toggle('far', !reveal);
        icon.classList.toggle('fa-eye', !reveal);
        icon.classList.toggle('fas', reveal);
        icon.classList.toggle('fa-eye-slash', reveal);
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    }

    /* ----------------------------------------------------------------------
       Field error styling
       (Errors are drawn inside the field by .field-error in login.css.)
       ---------------------------------------------------------------------- */
    function setFieldError(input, message) {
        const wrapper = input.closest('[data-field]');
        let note = byId(`${input.id}-error`);
        if (!note) {
            note = document.createElement('span');
            note.id = `${input.id}-error`;
            note.className = 'field-error';
            note.setAttribute('role', 'alert');
            wrapper.appendChild(note);
        }
        note.textContent = message;
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', note.id);
    }

    function clearFieldError(input) {
        if (!input.classList.contains('is-invalid')) return;
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
        byId(`${input.id}-error`)?.remove();
    }

    /* ----------------------------------------------------------------------
       API
       ---------------------------------------------------------------------- */
    async function submitAuthRequest(action, payload) {
        const response = await fetch(ENDPOINTS[action], {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...payload }),
        });

        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch {
            throw new Error(
                `The server returned a non-JSON response (HTTP ${response.status}). ` +
                'Check the backend URL and Apache/PHP error log.'
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'The request could not be completed.');
        }

        return result;
    }

    /* ----------------------------------------------------------------------
       Form handlers
       ---------------------------------------------------------------------- */
    async function handleLogin(event) {
        event.preventDefault();

        const ui = {
            button: byId('submitBtn'),
            label: byId('btnText'),
            spinner: byId('btnSpinner'),
        };

        setButtonLoading(ui, true, 'Logging in...', 'Login');

        try {
            const result = await submitAuthRequest('login', {
                identifier: byId('email').value,
                password: byId('password').value,
            });
            showToast(`Welcome back, ${result.user.name}!`, 'success');

            // Redirect to the homepage after the toast has shown briefly
            setTimeout(() => {
                window.location.href = HOME_PAGE;
            }, 1000);
        } catch (error) {
            showToast(error.message, 'error');
            setButtonLoading(ui, false, '', 'Login');
        }
    }

    /** Validates the sign-up form. Returns true when every field is valid. */
    function validateSignup(fields) {
        const { firstName, lastName, email, password, confirmPassword, agreeTerms, termsError } = fields;
        let isValid = true;

        if (!firstName.value || !NAME_REGEX.test(firstName.value)) {
            setFieldError(firstName, 'Invalid characters');
            isValid = false;
        }

        if (!lastName.value || !NAME_REGEX.test(lastName.value)) {
            setFieldError(lastName, 'Invalid characters');
            isValid = false;
        }

        if (!email.value || !EMAIL_REGEX.test(email.value)) {
            setFieldError(email, 'Invalid Email / Required');
            isValid = false;
        }

        if (!password.value || password.value.length < MIN_PASSWORD_LENGTH) {
            setFieldError(password, `Password length must be at least ${MIN_PASSWORD_LENGTH} characters`);
            isValid = false;
        }

        if (!confirmPassword.value || password.value !== confirmPassword.value) {
            setFieldError(confirmPassword, 'Passwords do not match');
            isValid = false;
        }

        termsError.classList.toggle('hidden', agreeTerms.checked);
        agreeTerms.classList.toggle('is-invalid', !agreeTerms.checked);
        if (!agreeTerms.checked) isValid = false;

        return isValid;
    }

    async function handleSignup(event) {
        event.preventDefault();

        const ui = {
            button: byId('signupSubmitBtn'),
            label: byId('signupBtnText'),
            spinner: byId('signupBtnSpinner'),
        };

        const fields = {
            firstName: byId('firstName'),
            lastName: byId('lastName'),
            email: byId('signupEmail'),
            password: byId('signupPassword'),
            confirmPassword: byId('confirmPassword'),
            agreeTerms: byId('agreeTerms'),
            termsError: byId('termsError'),
        };

        if (!validateSignup(fields)) {
            // Don't focus it: focusing clears the in-field message the user needs to read.
            document.querySelector('#signupForm .is-invalid')?.scrollIntoView({
                block: 'center',
                behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            });
            return;
        }

        setButtonLoading(ui, true, 'Creating Account...', 'Get Started');

        try {
            const result = await submitAuthRequest('signup', {
                firstName: fields.firstName.value,
                lastName: fields.lastName.value,
                email: fields.email.value,
                password: fields.password.value,
            });

            byId('email').value = fields.email.value; // pre-fill the login form
            showToast(`${result.message} Welcome, ${fields.firstName.value}!`, 'success');
            toggleAuthMode('login');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            setButtonLoading(ui, false, '', 'Get Started');
        }
    }

    /* ----------------------------------------------------------------------
       Event wiring
       ---------------------------------------------------------------------- */
    function init() {
        // Forms
        byId('loginForm').addEventListener('submit', handleLogin);
        byId('signupForm').addEventListener('submit', handleSignup);

        // Login <-> Sign Up switch links
        document.querySelectorAll('[data-auth-mode]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                toggleAuthMode(link.dataset.authMode);
            });
        });

        // Password visibility toggles
        document.querySelectorAll('[data-toggle-password]').forEach((button) => {
            button.addEventListener('click', () => {
                togglePasswordVisibility(button.dataset.togglePassword, button);
            });
        });

        // Social login buttons
        document.querySelectorAll('[data-social]').forEach((button) => {
            button.addEventListener('click', () => {
                showToast(`Connecting to ${button.dataset.social}...`, 'info');
            });
        });

        // Forgot password
        document.querySelector('[data-forgot-password]').addEventListener('click', (event) => {
            event.preventDefault();
            showToast('Password reset link sent to your email!', 'info');
        });

        // Toast close button
        byId('toast-close').addEventListener('click', dismissToast);

        // Clear in-field error messages as soon as the user focuses the field
        document.querySelectorAll('input:not([type="checkbox"])').forEach((input) => {
            input.addEventListener('focus', () => clearFieldError(input));
        });

        // Hide the terms error once the box is ticked
        byId('agreeTerms').addEventListener('change', (event) => {
            byId('termsError').classList.add('hidden');
            event.target.classList.remove('is-invalid');
        });

        // Deep link: login.html#signup opens the sign-up view
        if (location.hash === '#signup') toggleAuthMode('signup');
    }

    document.addEventListener('DOMContentLoaded', init);
})();

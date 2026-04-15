
(function() {
    const form = document.getElementById('register-form');
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    const clientError = document.getElementById('client-error');

    // Requirement elements
    const reqLength = document.getElementById('req-length');
    const reqUppercase = document.getElementById('req-uppercase');
    const reqLowercase = document.getElementById('req-lowercase');
    const reqNumber = document.getElementById('req-number');
    const reqSpecial = document.getElementById('req-special');
    const confirmMatch = document.getElementById('confirm-match');

    // Icons
    const unmetIcon = '○';
    const metIcon = '✓';

    function checkRequirements() {
        const pwd = password.value;

        // Length
        if (pwd.length >= 8) {
            setMet(reqLength, true);
        } else {
            setMet(reqLength, false);
        }

        // Uppercase
        if (/[A-Z]/.test(pwd)) {
            setMet(reqUppercase, true);
        } else {
            setMet(reqUppercase, false);
        }

        // Lowercase
        if (/[a-z]/.test(pwd)) {
            setMet(reqLowercase, true);
        } else {
            setMet(reqLowercase, false);
        }

        // Number
        if (/[0-9]/.test(pwd)) {
            setMet(reqNumber, true);
        } else {
            setMet(reqNumber, false);
        }

        // Special character (non-alphanumeric)
        if (/[^A-Za-z0-9]/.test(pwd)) {
            setMet(reqSpecial, true);
        } else {
            setMet(reqSpecial, false);
        }

        // Confirm match (if confirm field has any content)
        if (confirm.value.length > 0) {
            if (pwd === confirm.value) {
                setMet(confirmMatch, true);
            } else {
                setMet(confirmMatch, false);
            }
        } else {
            // If confirm is empty, show as unmet (grey circle)
            setMet(confirmMatch, false);
        }
    }

    function setMet(element, isMet) {
        const iconSpan = element.querySelector('.req-icon');
        if (isMet) {
            element.classList.add('requirement-met');
            iconSpan.textContent = metIcon;
        } else {
            element.classList.remove('requirement-met');
            iconSpan.textContent = unmetIcon;
        }
    }

    // Hide client error when user types in any relevant field
    [password, confirm].forEach(field => {
        field.addEventListener('input', function() {
            clientError.style.display = 'none';
            checkRequirements();
        });
    });

    // Also check on initial load (in case browser autofills)
    window.addEventListener('load', function() {
        checkRequirements();
    });

    // Final validation on submit (same as before)
    form.addEventListener('submit', function(e) {
        clientError.style.display = 'none';
        clientError.innerHTML = '';

        const passwordVal = password.value;
        const confirmVal = confirm.value;

        const errors = [];

        if (passwordVal.length < 8) {
            errors.push('Password must be at least 8 characters long.');
        }
        if (!/[A-Z]/.test(passwordVal)) {
            errors.push('Password must contain at least one uppercase letter.');
        }
        if (!/[a-z]/.test(passwordVal)) {
            errors.push('Password must contain at least one lowercase letter.');
        }
        if (!/[0-9]/.test(passwordVal)) {
            errors.push('Password must contain at least one number.');
        }
        if (!/[^A-Za-z0-9]/.test(passwordVal)) {
            errors.push('Password must contain at least one special character (e.g., !@#$%^&*).');
        }
        if (passwordVal !== confirmVal) {
            errors.push('Passwords do not match.');
        }

        if (errors.length > 0) {
            e.preventDefault();
            clientError.style.display = 'block';
            clientError.innerHTML = '<strong>Please fix the following:</strong><ul>' +
                errors.map(err => '<li>' + err + '</li>').join('') +
                '</ul>';
        }
    });
})();

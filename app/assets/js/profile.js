/**
 * profile.js – Handles tab switching and VAT checkbox toggle.
 */

document.addEventListener('DOMContentLoaded', function() {
    // ----- Tab switching -----
    const tabs = document.querySelectorAll('.tab');
    const tabContents = {
        personal: document.getElementById('personal'),
        address: document.getElementById('address'),
        kyc: document.getElementById('kyc'),
        tax: document.getElementById('tax'),
        security: document.getElementById('security')   // <-- added security tab
    };

    function switchTab(tabId) {
        // Update URL parameter without reloading
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);

        // Update active classes on tab buttons
        tabs.forEach(tab => {
            if (tab.dataset.tab === tabId) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });

        // Show the selected tab content, hide others
        Object.keys(tabContents).forEach(id => {
            if (id === tabId) {
                tabContents[id].classList.add('active');
            } else {
                tabContents[id].classList.remove('active');
            }
        });
    }

    // Attach click handlers to tabs
    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            switchTab(this.dataset.tab);
        });
    });

    // ----- VAT checkbox toggle (Tax tab) -----
    const vatCheck = document.querySelector('input[name="vat_registered"]');
    if (vatCheck) {
        const vatGroup = document.getElementById('vat_number_group');
        const vatInput = document.querySelector('input[name="vat_number"]');

        vatCheck.addEventListener('change', function() {
            if (this.checked) {
                vatGroup.style.display = 'block';
            } else {
                vatGroup.style.display = 'none';
                if (vatInput) vatInput.value = ''; // clear value when unchecked
            }
        });
    }
});
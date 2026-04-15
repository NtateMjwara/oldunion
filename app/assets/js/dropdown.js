(function() {
    'use strict';

    const avatarBtn = document.getElementById('AvatarBtn');
    const dropdown = document.getElementById('UserDropdown');

    function closeDropdown() {
        if (dropdown) dropdown.classList.remove('header-dropdown--open');
        if (avatarBtn) avatarBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleDropdown() {
        // Only allow dropdown on screens smaller than 1024px
        if (window.innerWidth >= 1024) {
            closeDropdown();
            return;
        }

        if (!dropdown || !avatarBtn) return;
        const isOpen = dropdown.classList.contains('header-dropdown--open');
        if (isOpen) {
            closeDropdown();
        } else {
            dropdown.classList.add('header-dropdown--open');
            avatarBtn.setAttribute('aria-expanded', 'true');
        }
    }

    // Handle resize: if width >= 1024, ensure dropdown is closed and event disabled
    function handleResize() {
        if (window.innerWidth >= 1024) {
            closeDropdown();
        }
    }

    if (avatarBtn) {
        avatarBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleDropdown();
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (dropdown && !dropdown.contains(e.target) && !avatarBtn.contains(e.target)) {
            closeDropdown();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    window.addEventListener('resize', handleResize);
    handleResize(); // initial check
})();
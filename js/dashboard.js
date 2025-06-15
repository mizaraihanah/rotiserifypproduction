document.addEventListener('DOMContentLoaded', function() {
    // Safely get elements and check if they exist
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    const body = document.body;
    const userDropdownToggle = document.getElementById('user-dropdown-toggle');
    const userDropdown = document.getElementById('user-dropdown');

    // Sidebar toggle - only if elements exist
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            body.classList.toggle('sidebar-collapsed');
        });
    }

    // User dropdown toggle - only if elements exist
    if (userDropdownToggle && userDropdown) {
        userDropdownToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userDropdown.contains(e.target) && !userDropdownToggle.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
    }

    // Submenu toggle - only if elements exist
    const submenuItems = document.querySelectorAll('.has-submenu > a');
    if (submenuItems.length > 0) {
        submenuItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.parentElement;
                parent.classList.toggle('active');
            });
        });
    }

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    if (alerts.length > 0) {
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 300);
            }, 5000);
        });
    }

    // Form validation helpers
    const forms = document.querySelectorAll('form');
    if (forms.length > 0) {
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;

                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('error');
                        isValid = false;
                    } else {
                        field.classList.remove('error');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    const firstError = form.querySelector('.error');
                    if (firstError) {
                        firstError.focus();
                    }
                }
            });
        });
    }

    // Number input validation
    const numberInputs = document.querySelectorAll('input[type="number"]');
    if (numberInputs.length > 0) {
        numberInputs.forEach(input => {
            input.addEventListener('input', function() {
                const value = parseFloat(this.value);
                const min = parseFloat(this.getAttribute('min'));
                const max = parseFloat(this.getAttribute('max'));

                if (min !== null && !isNaN(min) && value < min) {
                    this.setCustomValidity(`Value must be at least ${min}`);
                } else if (max !== null && !isNaN(max) && value > max) {
                    this.setCustomValidity(`Value must be at most ${max}`);
                } else {
                    this.setCustomValidity('');
                }
            });
        });
    }

    // Date input validation (prevent past dates)
    const dateInputs = document.querySelectorAll('input[type="date"]');
    if (dateInputs.length > 0) {
        dateInputs.forEach(input => {
            if (input.hasAttribute('data-min-today')) {
                const today = new Date().toISOString().split('T')[0];
                input.setAttribute('min', today);
            }
        });
    }

    // Confirm dialogs for dangerous actions
    const dangerousButtons = document.querySelectorAll('[data-confirm]');
    if (dangerousButtons.length > 0) {
        dangerousButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                const message = this.getAttribute('data-confirm');
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    // Search functionality
    const searchInputs = document.querySelectorAll('.search-input');
    if (searchInputs.length > 0) {
        searchInputs.forEach(input => {
            input.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const targetSelector = this.getAttribute('data-target');
                if (targetSelector) {
                    const items = document.querySelectorAll(targetSelector);

                    items.forEach(item => {
                        const text = item.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                }
            });
        });
    }
});

// Utility functions that can be used by other scripts
window.DashboardUtils = {
    showAlert: function(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert ${type}`;
        alert.textContent = message;
        
        const container = document.querySelector('.main-content') || document.body;
        container.insertBefore(alert, container.firstChild);
        
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 300);
        }, 5000);
    },

    formatNumber: function(num, decimals = 2) {
        return Number(num).toFixed(decimals);
    },

    formatDate: function(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }
};
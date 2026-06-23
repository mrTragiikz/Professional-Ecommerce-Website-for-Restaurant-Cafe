/* ============================================
   JUSTKLEEK – ASHFIELD | MENU.JS
   Order Popup Modal Functionality
   ============================================ */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    initializeMenuModal();
    initializeScrollAnimations();
    initializeCustomDropdown();
    // Pickup time selection removed
});

// ============================================
// ORDER MODAL FUNCTIONALITY
// ============================================

// Global variable for modal service type
let modalServiceType = 'delivery';

function initializeMenuModal() {
    const orderModal = document.getElementById('orderModal');
    const modalClose = document.getElementById('modalClose');
    const modalBackdrop = orderModal?.querySelector('.modal-backdrop');
    const menuCards = document.querySelectorAll('.menu-card');
    const qtyDecrease = document.getElementById('qtyDecrease');
    const qtyIncrease = document.getElementById('qtyIncrease');
    const qtyInput = document.getElementById('qtyInput');
    const btnOrderNow = document.getElementById('btnOrderNow');
    const modalDishName = document.getElementById('modalDishName');
    const modalDescription = document.getElementById('modalDescription');
    const specialInstructions = document.getElementById('specialInstructions');

    // Handle menu card buttons
    menuCards.forEach(card => {
        // Also handle button clicks - Direct redirect to basket
        const btn = card.querySelector('.menu-item-btn');
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();

                // Check if user is logged in
                console.log('Order Click - Auth values:', window.userLoggedIn, window.userVerified);

                // robust check for boolean or string "true"
                const isLoggedIn = window.userLoggedIn === true || window.userLoggedIn === 'true';

                if (!isLoggedIn) {
                    console.log('User not logged in, showing modal');
                    showLoginRequiredModal();
                    return;
                }

                // Check if user is verified
                const isVerified = window.userVerified === true;
                if (!isVerified) {
                    showVerificationRequired();
                    return;
                }

                const card = this.closest('.menu-card');
                // Use display attributes for proper capitalization
                const itemName = card.dataset.displayName || card.querySelector('.menu-item-name')?.textContent || '';
                const itemDescription = card.dataset.displayDescription || card.querySelector('.menu-item-description')?.textContent || '';

                if (!itemName) return;

                // Get price from menu item
                const priceElement = card.querySelector('.menu-item-price');
                const dishPrice = priceElement ? parseFloat(priceElement.textContent.replace(/Rs\./g, '').replace(/[^0-9.]/g, '')) || 0 : 0;

                // Get image
                const cardImage = card.querySelector('.menu-card-image img');
                const dishImage = cardImage ? cardImage.src : 'assets/plate.png';

                // Visual feedback - Add loading state
                const btnSpan = this.querySelector('span');
                const originalText = btnSpan ? btnSpan.textContent : 'Order Now';

                // Show loading state immediately
                if (btnSpan) {
                    btnSpan.textContent = 'Adding...';
                    btnSpan.style.opacity = '0.8';
                }
                this.style.pointerEvents = 'none';
                this.style.opacity = '0.7';

                // Add smooth transition effect
                card.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
                card.style.transform = 'scale(0.98)';

                // Add to cart and redirect immediately
                const formData = new URLSearchParams();
                formData.append('action', 'add');
                formData.append('id', Date.now());
                formData.append('name', itemName);
                formData.append('description', itemDescription);
                formData.append('price', dishPrice);
                formData.append('quantity', '1');
                formData.append('image', dishImage);

                // Use fetch with AbortController for timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000); // 3 second timeout

                fetch('cart', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString(),
                    signal: controller.signal
                })
                    .then(response => {
                        clearTimeout(timeoutId);
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        // Always redirect to basket regardless of success
                        if (btnSpan) {
                            btnSpan.textContent = 'Added!';
                            btnSpan.style.opacity = '1';
                        }
                        card.style.transform = 'scale(1)';
                        card.style.opacity = '1';

                        // Redirect immediately to basket
                        setTimeout(() => {
                            // Add smooth page transition
                            document.body.style.opacity = '0';
                            document.body.style.transition = 'opacity 0.2s ease';

                            setTimeout(() => {
                                window.location.href = 'basket';
                            }, 150);
                        }, 200);
                    })
                    .catch(error => {
                        clearTimeout(timeoutId);
                        console.error('Error adding to cart:', error);

                        // Always redirect to basket even on error
                        // Item might still be added, or user can add it manually
                        setTimeout(() => {
                            window.location.href = 'basket';
                        }, 300);
                    });
            });
        }

        // Handle cart button clicks (quick add to cart)
        const cartBtn = card.querySelector('.menu-cart-btn');
        if (cartBtn) {
            cartBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();

                // Check if user is logged in
                const isLoggedIn = window.userLoggedIn === true || window.userLoggedIn === 'true';
                if (!isLoggedIn) {
                    showLoginRequiredModal();
                    return;
                }

                // Check if user is verified
                const isVerified = window.userVerified === true || window.userVerified === 'true';
                if (!isVerified) {
                    showVerificationRequired();
                    return;
                }

                const card = this.closest('.menu-card');
                const itemName = card.dataset.displayName || card.querySelector('.menu-item-name')?.textContent || '';
                const itemDescription = card.dataset.displayDescription || card.querySelector('.menu-item-description')?.textContent || '';

                if (!itemName) return;

                // Get price from menu item
                const priceElement = card.querySelector('.menu-item-price');
                const dishPrice = priceElement ? parseFloat(priceElement.textContent.replace(/Rs\./g, '').replace(/[^0-9.]/g, '')) || 0 : 0;

                // Get image
                const cardImage = card.querySelector('.menu-card-image img');
                const dishImage = cardImage ? cardImage.src : 'assets/plate.png';

                // Visual feedback
                const originalHTML = this.innerHTML;
                this.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2L12 8L18 9L12 10L10 16L8 10L2 9L8 8L10 2Z" fill="currentColor"/></svg>';
                this.style.opacity = '0.7';
                this.style.pointerEvents = 'none';

                // Add to cart
                const formData = new URLSearchParams();
                formData.append('action', 'add');
                formData.append('id', Date.now());
                formData.append('name', itemName);
                formData.append('description', itemDescription);
                formData.append('price', dishPrice);
                formData.append('quantity', '1');
                formData.append('image', dishImage);

                fetch('cart', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success feedback
                            this.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.7071 5.29289C17.0976 5.68342 17.0976 6.31658 16.7071 6.70711L8.70711 14.7071C8.31658 15.0976 7.68342 15.0976 7.29289 14.7071L3.29289 10.7071C2.90237 10.3166 2.90237 9.68342 3.29289 9.29289C3.68342 8.90237 4.31658 8.90237 4.70711 9.29289L8 12.5858L15.2929 5.29289C15.6834 4.90237 16.3166 4.90237 16.7071 5.29289Z" fill="currentColor"/></svg>';
                            this.style.color = '#4CAF50';

                            // Show success notification
                            showCartSuccessNotification('Cart item successfully added!');

                            setTimeout(() => {
                                this.innerHTML = originalHTML;
                                this.style.opacity = '1';
                                this.style.color = '';
                                this.style.pointerEvents = '';
                            }, 1500);

                            // Update cart count in navbar if exists
                            updateCartCount(data.cart_count || 0);
                        } else {
                            // Show error message
                            alert(data.error || 'Failed to add item to cart');
                            this.innerHTML = originalHTML;
                            this.style.opacity = '1';
                            this.style.pointerEvents = '';
                        }
                    })
                    .catch(error => {
                        console.error('Error adding to cart:', error);
                        this.innerHTML = originalHTML;
                        this.style.opacity = '1';
                        this.style.pointerEvents = '';

                        // Show error feedback
                        this.style.color = '#ff4444';
                        setTimeout(() => {
                            this.style.color = '';
                        }, 2000);
                    });
            });
        }
    });

    // Close modal functions
    if (modalClose) {
        modalClose.addEventListener('click', closeOrderModal);
    }

    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', closeOrderModal);
    }

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && orderModal?.classList.contains('active')) {
            closeOrderModal();
        }
    });

    // Quantity controls - mobile-friendly (works on both touch and click)
    if (qtyDecrease) {
        let touchStartTime = 0;
        let touchStartY = 0;

        // Handle touch events for mobile
        qtyDecrease.addEventListener('touchstart', function (e) {
            touchStartTime = Date.now();
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        qtyDecrease.addEventListener('touchend', function (e) {
            const touchDuration = Date.now() - touchStartTime;
            const touchEndY = e.changedTouches[0].clientY;
            const deltaY = Math.abs(touchEndY - touchStartY);

            // Only trigger if it's a quick tap (not a swipe)
            if (touchDuration < 300 && deltaY < 10) {
                e.preventDefault();
                e.stopPropagation();
                const currentQty = parseInt(qtyInput.value) || 1;
                if (currentQty > 1) {
                    qtyInput.value = currentQty - 1;
                }
            }
        }, { passive: false });

        // Handle click events for desktop
        qtyDecrease.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const currentQty = parseInt(qtyInput.value) || 1;
            if (currentQty > 1) {
                qtyInput.value = currentQty - 1;
            }
        });
    }

    if (qtyIncrease) {
        let touchStartTime = 0;
        let touchStartY = 0;

        // Handle touch events for mobile
        qtyIncrease.addEventListener('touchstart', function (e) {
            touchStartTime = Date.now();
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        qtyIncrease.addEventListener('touchend', function (e) {
            const touchDuration = Date.now() - touchStartTime;
            const touchEndY = e.changedTouches[0].clientY;
            const deltaY = Math.abs(touchEndY - touchStartY);

            // Only trigger if it's a quick tap (not a swipe)
            if (touchDuration < 300 && deltaY < 10) {
                e.preventDefault();
                e.stopPropagation();
                const currentQty = parseInt(qtyInput.value) || 1;
                qtyInput.value = currentQty + 1;
            }
        }, { passive: false });

        // Handle click events for desktop
        qtyIncrease.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const currentQty = parseInt(qtyInput.value) || 1;
            qtyInput.value = currentQty + 1;
        });
    }

    // Delivery Only Enforced (Toggle removed)
    const orderBtnText = document.getElementById('orderBtnText');
    const modalDeliveryLocation = document.getElementById('modalDeliveryLocation');
    const modalPickupTime = document.getElementById('modalPickupTime');

    // Default state: Delivery
    if (orderBtnText) orderBtnText.textContent = 'Order for Delivery';
    if (modalDeliveryLocation) modalDeliveryLocation.style.display = 'block';
    if (modalPickupTime) modalPickupTime.style.display = 'none';



    // Order Now button
    if (btnOrderNow) {
        btnOrderNow.addEventListener('click', function () {
            // Check if user is logged in
            const isLoggedIn = window.userLoggedIn === true || window.userLoggedIn === 'true';
            if (!isLoggedIn) {
                closeOrderModal();
                showLoginRequiredModal();
                return;
            }

            // Check if user is verified
            const isVerified = window.userVerified === true || window.userVerified === 'true';
            if (!isVerified) {
                closeOrderModal();
                showVerificationRequired();
                return;
            }

            const dishName = modalDishName?.textContent || '';
            const quantity = parseInt(qtyInput?.value) || 1;
            const instructions = specialInstructions?.value || '';
            const modalDishImage = document.getElementById('modalDishImage');
            const dishImage = modalDishImage?.src || '';

            // Get service type and related info
            const serviceType = 'delivery';
            const deliverySuburb = (document.getElementById('modalDeliverySuburbValue')?.value || document.getElementById('modalDeliverySuburb')?.value || '');
            const pickupTime = '';

            // Get price from menu item if available, otherwise default to 0
            const card = document.querySelector(`[data-display-name="${dishName}"]`);
            const priceElement = card?.querySelector('.menu-item-price');
            const dishPrice = priceElement ? parseFloat(priceElement.textContent.replace(/Rs\./g, '').replace(/[^0-9.]/g, '')) || 0 : 0;

            // Close modal first
            closeOrderModal();

            // Add to cart with image
            fetch('cart', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add&id=${Date.now()}&name=${encodeURIComponent(dishName)}&description=${encodeURIComponent(modalDescription?.textContent || '')}&price=${dishPrice}&quantity=${quantity}&image=${encodeURIComponent(dishImage)}&service=${serviceType}&deliverySuburb=${encodeURIComponent(deliverySuburb)}&pickupTime=${encodeURIComponent(pickupTime)}`
            })
                .then(response => response.json())
                .then(data => {
                    // Always redirect to basket
                    window.location.href = 'basket';
                })
                .catch(error => {
                    console.error('Error adding to cart:', error);
                    // Always redirect to basket even on error
                    window.location.href = 'basket';
                });
        });
    }
}

function openOrderModal(itemName, itemDescription) {
    // Check if user is logged in
    const isLoggedIn = window.userLoggedIn === true || window.userLoggedIn === 'true';
    if (!isLoggedIn) {
        showLoginRequiredModal();
        return;
    }

    // Check if user is verified
    const isVerified = window.userVerified === true || window.userVerified === 'true';
    if (!isVerified) {
        showVerificationRequired();
        return;
    }

    const orderModal = document.getElementById('orderModal');
    if (!orderModal) return;

    // Cache DOM elements for performance
    const modalDishName = document.getElementById('modalDishName');
    const modalDescription = document.getElementById('modalDescription');
    const modalDishImage = document.getElementById('modalDishImage');
    const qtyInput = document.getElementById('qtyInput');
    const specialInstructions = document.getElementById('specialInstructions');
    const orderBtnText = document.getElementById('orderBtnText');

    // Find the card to get the image - optimized query
    const card = document.querySelector(`[data-display-name="${itemName}"]`);

    // Get image from card - use requestAnimationFrame for smooth update
    if (card && modalDishImage) {
        const cardImage = card.querySelector('.menu-card-image img');
        if (cardImage) {
            modalDishImage.src = cardImage.src;
            modalDishImage.alt = itemName;
        } else {
            modalDishImage.src = 'assets/plate.png';
            modalDishImage.alt = itemName;
        }
    }

    // Set modal content immediately for fast response
    if (modalDishName) modalDishName.textContent = itemName;
    if (modalDescription) modalDescription.textContent = itemDescription;
    if (qtyInput) qtyInput.value = 1;
    if (specialInstructions) specialInstructions.value = '';
    if (orderBtnText) orderBtnText.textContent = 'Order for Delivery';

    // Set service to Delivery
    const modalDeliveryLocation = document.getElementById('modalDeliveryLocation');
    const modalPickupTime = document.getElementById('modalPickupTime');
    modalServiceType = 'delivery';
    if (modalDeliveryLocation) modalDeliveryLocation.style.display = 'block';
    if (modalPickupTime) modalPickupTime.style.display = 'none';

    // Show modal immediately - no animation for faster response
    orderModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeOrderModal() {
    const orderModal = document.getElementById('orderModal');
    if (!orderModal) return;

    // Immediate close for better performance
    orderModal.classList.remove('active');
    document.body.style.overflow = '';
}

// ============================================
// LOGIN REQUIRED MODAL FUNCTIONALITY
// ============================================

function showLoginRequiredModal() {
    const loginModal = document.getElementById('loginRequiredModal');
    if (!loginModal) return;

    // Store current scroll position if not already stored
    if (!document.body.classList.contains('scroll-locked')) {
        const scrollY = window.scrollY;
        document.body.style.top = `-${scrollY}px`;
        document.body.dataset.scrollY = scrollY;
    }

    loginModal.classList.add('active');
    document.body.classList.add('scroll-locked');
    document.body.style.overflow = 'hidden';
    document.body.style.position = 'fixed';
    document.body.style.width = '100%';
}

function closeLoginRequiredModal() {
    const loginModal = document.getElementById('loginRequiredModal');
    if (!loginModal) return;

    loginModal.classList.remove('active');

    // Restore scroll position
    const scrollY = document.body.dataset.scrollY;
    document.body.classList.remove('scroll-locked');
    document.body.style.overflow = '';
    document.body.style.position = '';
    document.body.style.width = '';
    document.body.style.top = '';

    if (scrollY) {
        window.scrollTo(0, parseInt(scrollY || '0'));
    }
}

function showVerificationRequired() {
    // Use the global showVerificationModal function if available (from verification_modal.php)
    if (typeof window.showVerificationModal === 'function') {
        window.showVerificationModal();
        return;
    }

    // Check for local modal element
    const verificationModal = document.getElementById('verificationRequiredModal');
    if (verificationModal) {
        // Store current scroll position if not already stored
        if (!document.body.classList.contains('scroll-locked')) {
            const scrollY = window.scrollY;
            document.body.style.top = `-${scrollY}px`;
            document.body.dataset.scrollY = scrollY;
        }

        verificationModal.classList.add('active');
        document.body.classList.add('scroll-locked');
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        return;
    }

    // Fallback: direct redirect if modal missing
    const basePath = window.basePath || '';
    window.location.href = basePath + '/profile';
}

// Initialize login required modal
document.addEventListener('DOMContentLoaded', function () {
    const loginModal = document.getElementById('loginRequiredModal');
    const loginModalClose = document.getElementById('loginModalClose');
    const loginModalBackdrop = loginModal?.querySelector('.modal-backdrop');
    const verificationModal = document.getElementById('verificationRequiredModal');
    const verificationModalClose = document.getElementById('verificationModalClose');
    const verificationModalBackdrop = verificationModal?.querySelector('.modal-backdrop');
    const verificationModalCancelBtn = document.getElementById('verificationModalCancelBtn');
    const verificationModalVerifyBtn = document.getElementById('verificationModalVerifyBtn');

    if (loginModalClose) {
        loginModalClose.addEventListener('click', closeLoginRequiredModal);
    }

    if (loginModalBackdrop) {
        loginModalBackdrop.addEventListener('click', closeLoginRequiredModal);
    }

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && loginModal?.classList.contains('active')) {
            closeLoginRequiredModal();
        }
    });

    // Prevent background scrolling on mobile when touching the backdrop
    if (loginModalBackdrop) {
        loginModalBackdrop.addEventListener('touchmove', function (e) {
            e.preventDefault();
        }, { passive: false });
    }

    // Verification modal close handlers (reuse scroll unlock from closeLoginRequiredModal)
    function closeVerificationModal() {
        if (!verificationModal) return;
        verificationModal.classList.remove('active');
        const scrollY = document.body.dataset.scrollY;
        document.body.classList.remove('scroll-locked');
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        if (scrollY) window.scrollTo(0, parseInt(scrollY || '0'));
    }

    if (verificationModalClose) {
        verificationModalClose.addEventListener('click', closeVerificationModal);
    }
    if (verificationModalBackdrop) {
        verificationModalBackdrop.addEventListener('click', closeVerificationModal);
    }
    if (verificationModalCancelBtn) {
        verificationModalCancelBtn.addEventListener('click', closeVerificationModal);
    }

    if (verificationModalVerifyBtn) {
        verificationModalVerifyBtn.addEventListener('click', function () {
            const basePath = window.basePath || '';
            window.location.href = basePath + '/profile';
        });
    }

});

// ============================================
// ============================================
// CUSTOM DROPDOWN FUNCTIONALITY
// ============================================

function initializeCustomDropdown() {
    const suburbs = [
        'Bharatpur-10', 'Bharatpur-11', 'Bharatpur-12', 'Bhojad', 'Aptari', 'Rampur', 'Narayangarh', 'Gita Nagar',
        'OTHER (Enter manually)'
    ];

    const dropdown = document.getElementById('deliverySuburbDropdown');
    const dropdownInput = document.getElementById('modalDeliverySuburb');
    const dropdownList = document.getElementById('deliverySuburbList');
    const searchInput = document.getElementById('suburbSearchInput');
    const optionsList = document.getElementById('suburbOptionsList');
    const hiddenInput = document.getElementById('modalDeliverySuburbValue');

    if (!dropdown || !dropdownInput || !dropdownList) return;

    let selectedValue = '';
    let filteredSuburbs = [...suburbs];

    // Populate options
    function renderOptions() {
        optionsList.innerHTML = '';
        filteredSuburbs.forEach(suburb => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'dropdown-option' + (suburb === selectedValue ? ' selected' : '');
            option.textContent = suburb;
            option.addEventListener('click', () => selectOption(suburb));
            optionsList.appendChild(option);
        });
    }

    function selectOption(suburb) {
        selectedValue = suburb;
        dropdownInput.value = suburb;

        // Handle "OTHER" option - allow manual entry
        if (suburb === 'OTHER (Enter manually)') {
            // Make input editable for manual entry
            dropdownInput.readOnly = false;
            dropdownInput.placeholder = 'Enter your suburb manually (e.g., SUBURB NAME NSW POSTCODE)';
            dropdownInput.focus();
            if (hiddenInput) hiddenInput.value = '';
        } else {
            dropdownInput.readOnly = true;
            dropdownInput.placeholder = 'Select your location...';
            if (hiddenInput) hiddenInput.value = suburb;
        }

        dropdown.classList.remove('active');
        searchInput.value = '';
        renderOptions();
    }

    // Update hidden input when manual entry is made for "OTHER"
    if (dropdownInput) {
        dropdownInput.addEventListener('blur', function () {
            if (this.value && this.value !== 'OTHER (Enter manually)' && !this.readOnly) {
                if (hiddenInput) hiddenInput.value = this.value.trim();
            }
        });
    }

    // Expose selectOption globally for use by "Use Current Location" button
    window.selectSuburbOption = selectOption;

    function filterOptions(searchTerm) {
        const term = searchTerm.toLowerCase().trim();
        if (term === '') {
            filteredSuburbs = [...suburbs];
        } else {
            filteredSuburbs = suburbs.filter(suburb =>
                suburb.toLowerCase().includes(term)
            );
        }
        renderOptions();
    }

    // Toggle dropdown
    dropdownInput.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('active');
        if (dropdown.classList.contains('active')) {
            searchInput.focus();
        }
    });

    // Search functionality
    searchInput.addEventListener('input', (e) => {
        filterOptions(e.target.value);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && filteredSuburbs.length > 0) {
            selectOption(filteredSuburbs[0]);
            e.preventDefault();
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });

    // Allow typing in main input to search
    dropdownInput.addEventListener('input', (e) => {
        if (!dropdown.classList.contains('active')) {
            dropdown.classList.add('active');
        }
        filterOptions(e.target.value);
        searchInput.value = e.target.value;
    });

    // Make input editable when focused
    dropdownInput.addEventListener('focus', () => {
        dropdownInput.readOnly = false;
        dropdown.classList.add('active');
    });

    // Initial render
    renderOptions();
}

// ============================================
// PICKUP TIME DROPDOWN FUNCTIONALITY
// ============================================

/*
function initializePickupTimeDropdown() {
    // ... logic removed ...
}
*/

// SCROLL ANIMATIONS (Fallback for older browsers)
// ============================================

function initializeScrollAnimations() {
    // Check if browser supports animation-timeline
    if (!CSS.supports('animation-timeline', 'view()')) {
        // Use Intersection Observer as fallback
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        // Observe elements that need scroll animations
        const animatedElements = document.querySelectorAll(
            '.fade-up, .fade-left, .fade-right, .scale-in, .slide-up, .categories-section'
        );

        animatedElements.forEach(el => {
            observer.observe(el);
        });
    }
}

// ============================================
// CONTACT FORM HANDLING
// ============================================

const contactForm = document.getElementById('contactForm');
if (contactForm) {
    contactForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = {
            name: document.getElementById('contactName').value,
            email: document.getElementById('contactEmail').value,
            message: document.getElementById('contactMessage').value
        };

        // Here you can add actual form submission logic
        // For now, just show an alert
        alert('Thank you for your message! We will get back to you soon.');

        // Reset form
        contactForm.reset();
    });
}

// ============================================
// SMOOTH SCROLL FOR ANCHOR LINKS
// ============================================

document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href === '#') return;

        e.preventDefault();
        const target = document.querySelector(href);

        if (target) {
            const offset = 80; // Navbar height
            const targetPosition = target.offsetTop - offset;

            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// ============================================
// CART SUCCESS NOTIFICATION
// ============================================

function showCartSuccessNotification(message) {
    // Remove existing notification if any
    const existingNotification = document.querySelector('.cart-success-notification');
    if (existingNotification) {
        existingNotification.remove();
    }

    // Create notification element
    const notification = document.createElement('div');
    notification.className = 'cart-success-notification';
    notification.innerHTML = `
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M16.7071 5.29289C17.0976 5.68342 17.0976 6.31658 16.7071 6.70711L8.70711 14.7071C8.31658 15.0976 7.68342 15.0976 7.29289 14.7071L3.29289 10.7071C2.90237 10.3166 2.90237 9.68342 3.29289 9.29289C3.68342 8.90237 4.31658 8.90237 4.70711 9.29289L8 12.5858L15.2929 5.29289C15.6834 4.90237 16.3166 4.90237 16.7071 5.29289Z" fill="currentColor"/>
        </svg>
        <span>${message}</span>
    `;

    // Add to body
    document.body.appendChild(notification);

    // Trigger animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);

    // Remove after 3 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, 3000);
}

// ============================================
// CART COUNT UPDATE FUNCTION
// ============================================

function updateCartCount(count) {
    // Update cart count badge if it exists
    const cartLink = document.querySelector('.navbar__cart-link');
    if (cartLink) {
        // Remove existing badge
        const existingBadge = cartLink.querySelector('.cart-count-badge');
        if (existingBadge) {
            existingBadge.remove();
        }

        // Add badge if count > 0
        if (count > 0) {
            const badge = document.createElement('span');
            badge.className = 'cart-count-badge';
            badge.textContent = count;
            badge.style.cssText = 'position: absolute; top: -8px; right: -8px; background: #ff4444; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: bold;';
            cartLink.style.position = 'relative';
            cartLink.appendChild(badge);
        }
    }
}

// Initialize cart count on page load
document.addEventListener('DOMContentLoaded', function () {
    fetch('cart.php?action=get_count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateCartCount(data.cart_count || 0);
            }
        })
        .catch(error => {
            console.error('Error fetching cart count:', error);
        });
});

// ============================================
// UTILITY FUNCTIONS
// ============================================

// Debounce function for performance
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Throttle function for scroll events
function throttle(func, limit) {
    let inThrottle;
    return function () {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// Add smooth scroll behavior for better UX
window.addEventListener('scroll', throttle(function () {
    // Any scroll-based animations can go here
}, 100));



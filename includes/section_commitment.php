<?php
// Commitment & Values Section - Professional & Mobile Responsive Update
?>
<style>
    .values-section {
        padding: 100px 20px;
        background: linear-gradient(-45deg,
                #FFFFFF, #FDFDFD, #FAFAF9,
                #F9F9F8, #FFFFFF, #FDFCFB,
                #FAF9F6, #F8F8F8, #FFFFFF);
        background-size: 400% 400%;
        animation: specialRgbShift 12s ease infinite;
        position: relative;
        overflow: hidden;
    }

    @keyframes specialRgbShift {
        0% {
            background-position: 0% 50%;
        }

        50% {
            background-position: 100% 50%;
        }

        100% {
            background-position: 0% 50%;
        }
    }

    .values-container {
        max-width: 1200px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        /* Text slightly wider */
        gap: 80px;
        align-items: center;
        position: relative;
        z-index: 2;
    }

    /* --- Text Side Styling --- */
    .values-content {
        padding-right: 0;
    }

    .values-badge {
        display: inline-flex;
        align-items: center;
        background: rgba(227, 24, 55, 0.08);
        color: #E31837;
        padding: 10px 20px;
        border-radius: 100px;
        font-size: 15px;
        font-weight: 800;
        /* Extra bold */
        text-transform: uppercase;
        letter-spacing: 1.0px;
        /* Reduced slightly to balance bolder weight */
        margin-bottom: 24px;
        border: 1px solid rgba(227, 24, 55, 0.15);

    }

    .values-title {
        font-size: 52px;
        font-weight: 900;
        line-height: 1.1;
        color: #000000;
        margin-bottom: 48px;
        letter-spacing: -0.03em;
    }

    .values-title span {
        position: relative;
        display: inline-block;
    }

    .colorful-animated-word {
        /* Dark but vivid/bright colourful gradient */
        background: linear-gradient(-45deg, #D50000, #AA00FF, #0011FF, #00C853, #FF6D00, #D50000);
        background-size: 300% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        animation: colorfulGradientFlow 3.5s linear infinite;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.25));
        /* Crisp shadow for extreme contrast on white */
        transition: opacity 0.8s ease-in-out;
        padding-bottom: 5px;
        font-weight: 900;
        opacity: 1;
    }

    @keyframes colorfulGradientFlow {
        0% {
            background-position: 0% center;
        }

        100% {
            background-position: 300% center;
        }
    }

    .values-list {
        display: grid;
        grid-template-columns: 1fr;
        gap: 32px;
    }

    .value-item {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        padding: 28px;
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.4);
        /* Reduced opacity for better blending */
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.5);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.015);
        transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
        animation: cardsBreathing 6s ease-in-out infinite;
    }

    .value-item:hover {
        background: #ffffff;
        transform: translateY(-8px) scale(1.02) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.08) !important;
        border-color: rgba(227, 24, 55, 0.1) !important;
        animation-play-state: paused;
    }

    @keyframes cardsBreathing {

        0%,
        100% {
            transform: translateY(0);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.015);
            border-color: rgba(255, 255, 255, 0.5);
        }

        50% {
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.04);
            border-color: rgba(255, 255, 255, 0.8);
        }
    }

    .values-list>div:nth-child(1) .value-item {
        animation-delay: 0.0s;
    }

    .values-list>div:nth-child(2) .value-item {
        animation-delay: 1.5s;
    }

    .values-list>div:nth-child(3) .value-item {
        animation-delay: 3.0s;
    }

    .values-list>div:nth-child(4) .value-item {
        animation-delay: 4.5s;
    }

    .value-icon {
        flex-shrink: 0;
        width: 58px;
        height: 58px;
        background: #ffffff;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        transition: all 0.4s ease;
        overflow: hidden;
    }

    .value-item:hover .value-icon {
        transform: scale(1.1) translateY(-3px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.08);
        /* Kept elegant without rotation wobble */
    }

    /* Professional Outline SVG Styling */
    .value-icon svg {
        width: 28px;
        height: 28px;
        display: block;
        stroke-width: 2.2px;
    }

    .value-text h3 {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 8px 0;
    }

    .value-text p {
        font-size: 15px;
        line-height: 1.6;
        color: #64748b;
        margin: 0;
        font-weight: 400;
        font-family: 'Poppins', sans-serif;
        /* Better for Nepali if needed */
    }

    /* --- Image Side Styling --- */
    .chef-image-wrapper {
        position: relative;
        padding: 40px;
    }

    .chef-image-frame {
        position: relative;
        border-radius: 32px;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        background: #f8fafc;
        aspect-ratio: 4/5;
        transform: rotate(2deg);
        /* Stylish slight tilt */
        transition: transform 0.5s ease;
        border: 8px solid white;
    }

    .chef-image-wrapper:hover .chef-image-frame {
        transform: rotate(0deg) scale(1.02);
    }

    .chef-image-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .decor-blob {
        position: absolute;
        z-index: -1;
        filter: blur(60px);
        opacity: 0.6;
    }

    .decor-blob-1 {
        top: -10%;
        right: -10%;
        width: 400px;
        height: 400px;
        background: #FFF9F2;
        /* Creamy warm */
        border-radius: 50%;
        animation: pulseBlob 8s infinite alternate;
    }

    .decor-blob-2 {
        bottom: -5%;
        left: -10%;
        width: 300px;
        height: 300px;
        background: #FDF8F0;
        /* Lighter creamy */
        border-radius: 50%;
    }

    @keyframes pulseBlob {
        0% {
            transform: scale(1);
            opacity: 0.6;
        }

        100% {
            transform: scale(1.1);
            opacity: 0.4;
        }
    }

    /* --- Mobile Responsiveness --- */
    @media (max-width: 1024px) {
        .values-container {
            gap: 40px;
            grid-template-columns: 1fr 1fr;
        }

        .values-title {
            font-size: 36px;
        }
    }

    @media (max-width: 960px) {
        .values-section {
            padding: 60px 20px;
        }

        .values-container {
            grid-template-columns: 1fr;
            gap: 50px;
            display: flex;
            /* Flex is better for reordering */
            flex-direction: column-reverse;
            display: block;
            /* Standard flow: Content then Image */
        }

        /* Move image to bottom */
        .chef-image-wrapper {
            margin: 0 auto;
            max-width: 100%;
            width: 100%;
            padding: 20px 0;
        }

        .chef-image-frame {
            max-width: 400px;
            /* Limit width on mobile so it doesn't look absurd */
            margin: 0 auto;
            transform: rotate(0deg);
            /* Remove tilt on mobile for cleanliness */
        }



        .values-content {
            text-align: left;
            /* Keep left alignment for readability */
        }

        .values-title {
            font-size: 32px;
            margin-bottom: 32px;
        }

        .value-item {
            padding: 16px;
            gap: 16px;
            border-radius: 16px;
        }

        .value-icon {
            width: 44px;
            height: 44px;
        }

        .value-icon svg {
            width: 24px;
            height: 24px;
        }
    }

    @media (max-width: 480px) {
        .values-title {
            font-size: 28px;
        }

        .values-badge {
            font-size: 11px;
        }
    }
</style>

<section class="values-section">
    <!-- Background Elements -->
    <div class="decor-blob decor-blob-1"></div>
    <div class="decor-blob decor-blob-2"></div>

    <div class="values-container">
        <!-- Left Side: Content -->
        <div class="values-content">
            <div class="values-badge cinematic-slide-in-left" style="transition-delay: 0.1s;">
                Justkleek नै किन त?
            </div>
            <h2 class="values-title cinematic-slide-in-left" style="transition-delay: 0.2s;">
                We Care About Your <br><span id="dynamic-value-word" class="colorful-animated-word">Food
                    Experience</span>
            </h2>

            <div class="values-list">
                <!-- Value 1: Hygiene (Blue Shield) -->
                <div class="cinematic-slide-in-left" style="transition-delay: 0.4s;">
                    <div class="value-item">
                        <div class="value-icon">
                            <!-- Professional Shield Check -->
                            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div class="value-text">
                            <h3>Hygienic & Clean Kitchen</h3>
                            <p>हाम्रो भान्सा सधैं सफा र सुरक्षित हुन्छ। तपाईंको स्वास्थ्य नै हाम्रो पहिलो प्राथमिकता हो।
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Value 2: Quality Taste (Gold Star) -->
                <div class="cinematic-slide-in-left" style="transition-delay: 0.6s;">
                    <div class="value-item">
                        <div class="value-icon">
                            <!-- Professional Star -->
                            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" xmlns="http://www.w3.org/2000/svg">
                                <polygon
                                    points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div class="value-text">
                            <h3>Fresh Quality Food</h3>
                            <p>हामी सधैं ताजा र अर्गानिक सामग्री मात्र प्रयोग गर्छौं। स्वाद र गुणस्तरमा कुनै सम्झौता
                                छैन।
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Value 3: Reliable Service (Red Clock) -->
                <div class="cinematic-slide-in-left" style="transition-delay: 0.8s;">
                    <div class="value-item">
                        <div class="value-icon">
                            <!-- Professional Timer/Clock -->
                            <svg viewBox="0 0 24 24" fill="none" stroke="#e31837" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round" />
                                <polyline points="12 6 12 12 16 14" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div class="value-text">
                            <h3>Fast & Hot Delivery</h3>
                            <p>भोक लाग्दा कुर्नु पर्दैन। अर्डर गर्नुस्, तातो र मीठो खाना छिट्टै तपाईंको घरदैलोमा
                                आइपुग्छ।
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Value 4: Authentic Taste (Green Leaf) -->
                <div class="cinematic-slide-in-left" style="transition-delay: 1.0s;">
                    <div class="value-item">
                        <div class="value-icon">
                            <!-- Professional Leaf -->
                            <svg viewBox="0 0 24 24" fill="none" stroke="#059669" xmlns="http://www.w3.org/2000/svg">
                                <path d="M11 20A7 7 0 0 1 14 6c3 0 7-4 7-4s-4 4-4 7a7 7 0 0 1-14 0"
                                    stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M14 6v6a3 3 0 0 1-6 0" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div class="value-text">
                            <h3>Authentic Local Taste</h3>
                            <p>हजुरआमाको हातको स्वाद जस्तै, घरको याद दिलाउने अर्गानिक र आफ्नै पन भएको परिकार।</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Chief Photo Placeholder -->
        <div class="chef-image-wrapper cinematic-slide-in-right" style="transition-delay: 0.6s;">
            <div class="chef-image-frame">
                <!-- Main Featured Image -->
                <img src="<?php echo $basePath; ?>/assets/image.png" alt="JustKleek Quality Experience">
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const words = ["Food Experience", "Quality", "Time", "Health", "Feelings"];
        let currentIndex = 0;
        const animatedText = document.getElementById('dynamic-value-word');

        if (animatedText) {
            setInterval(() => {
                // start fade out
                animatedText.style.opacity = '0';

                setTimeout(() => {
                    // Update word while invisible
                    currentIndex = (currentIndex + 1) % words.length;
                    animatedText.textContent = words[currentIndex];

                    // Trigger fade in
                    animatedText.style.opacity = '1';
                }, 800); // Matches the 0.8s CSS transition time
            }, 3500); // Change word every 3.5 seconds to give reading time
        }
    });
</script>

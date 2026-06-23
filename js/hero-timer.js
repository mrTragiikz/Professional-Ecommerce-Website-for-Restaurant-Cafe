/**
 * Hero Section Timer Logic
 * Handles the Australian clock and Opening/Closing countdown
 * Features:
 * - Robust Sydney Time calculation
 * - Fail-safe DOM updates
 * - Auto-recovery on error
 */

(function () {
    console.log("Hero Timer: v1.0.5 Starting...");

    // --- CONFIGURATION ---
    const TIMEZONE = 'Asia/Kathmandu';
    const OPEN_HOUR = 11;
    const OPEN_MINUTE = 0;
    const CLOSE_HOUR = 2; // 2:00 AM (Next Day logic handled in tick)
    const CLOSE_MINUTE = 0;

    // --- DOM CACHE ---
    const el = {
        h: document.getElementById('clockHours'),
        m: document.getElementById('clockMinutes'),
        s: document.getElementById('clockSeconds'),
        date: document.getElementById('digitalDate'),
        heading: document.getElementById('timerHeading'),
        digits: document.querySelectorAll('#openingTimer .timer-digit'),
        subTitle: document.getElementById('openTitle')
    };

    // --- HELPERS ---
    const pad = (n) => n.toString().padStart(2, '0');

    // Get strictly formatted parts for Nepali Time
    function getNepaliTime() {
        try {
            const now = new Date();
            // We use standard options to force numeric components
            const options = {
                timeZone: TIMEZONE,
                hourCycle: 'h23', // Force 0-23 hours
                year: 'numeric',
                month: 'short',
                weekday: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
                second: 'numeric'
            };

            const fmt = new Intl.DateTimeFormat('en-US', options);
            const parts = fmt.formatToParts(now);

            const get = (k) => parts.find(p => p.type === k)?.value;

            return {
                weekday: get('weekday').toUpperCase(),
                month: get('month').toUpperCase(),
                day: parseInt(get('day'), 10),
                year: parseInt(get('year'), 10),
                hour: parseInt(get('hour'), 10),
                minute: parseInt(get('minute'), 10),
                second: parseInt(get('second'), 10)
            };
        } catch (e) {
            console.error("Hero Timer: TimeZone Error", e);
            // Fallback for safety (Client Local Time)
            const n = new Date();
            return {
                weekday: 'UNK',
                month: 'JAN',
                day: n.getDate(),
                year: n.getFullYear(),
                hour: n.getHours(),
                minute: n.getMinutes(),
                second: n.getSeconds()
            };
        }
    }

    const MONTH_MAP = {
        'JAN': 0, 'FEB': 1, 'MAR': 2, 'APR': 3, 'MAY': 4, 'JUN': 5,
        'JUL': 6, 'AUG': 7, 'SEP': 8, 'OCT': 9, 'NOV': 10, 'DEC': 11
    };

    // --- MAIN TICK ---
    function tick() {
        try {
            // 1. Get Time
            const t = getNepaliTime();

            // 2. Update Top Clock
            if (el.h) {
                // 12-hour display format logic
                let h12 = t.hour % 12;
                if (h12 === 0) h12 = 12;
                el.h.innerText = pad(h12);
            }
            if (el.m) el.m.innerText = pad(t.minute);
            if (el.s) el.s.innerText = pad(t.second);

            // 3. Update Date Label
            if (el.date) {
                // Format: FRI 2 JAN - NEPAL TIME
                el.date.innerText = `${t.weekday} ${t.day} ${t.month} - NEPAL TIME`;
            }

            // 4. Countdown Logic
            // Create abstract dates in local 'space' to compare values
            // (We treat the Sydney Time components as if they are local time to do math)
            const nowAbs = new Date(t.year, MONTH_MAP[t.month] || 0, t.day, t.hour, t.minute, t.second);

            // Define Open/Close Targets for TODAY
            const openAbs = new Date(nowAbs);
            openAbs.setHours(OPEN_HOUR, OPEN_MINUTE, 0, 0);

            const closeAbs = new Date(nowAbs);
            closeAbs.setHours(CLOSE_HOUR, CLOSE_MINUTE, 0, 0);

            let targetAbs;
            let headText = "OPENING IN";
            let subText = "WE OPEN IN";

            if (nowAbs < openAbs) {
                // Case: Morning before Open
                targetAbs = openAbs;
                headText = "OPENING IN";
                subText = "WE OPEN IN";
            } else if (nowAbs >= openAbs && nowAbs < closeAbs) {
                // Case: Open, before Close
                targetAbs = closeAbs;
                headText = "CLOSING IN";
                subText = "WE CLOSE IN";
            } else {
                // Case: After Close -> Tomorrow Open
                targetAbs = new Date(openAbs);
                targetAbs.setDate(targetAbs.getDate() + 1);
                headText = "OPENING IN";
                subText = "WE OPEN IN";
            }

            // Time Difference
            let diff = targetAbs - nowAbs;
            if (diff < 0) diff = 0;

            const dh = Math.floor(diff / 3600000);
            const dm = Math.floor((diff % 3600000) / 60000);
            const ds = Math.floor((diff % 60000) / 1000);

            // Update Countdown Digits
            if (el.digits && el.digits.length >= 3) {
                el.digits[0].innerText = pad(dh);
                el.digits[1].innerText = pad(dm);
                el.digits[2].innerText = pad(ds);
            }

            // Update Headings
            if (el.heading) el.heading.innerText = headText;
            if (el.subTitle) el.subTitle.innerText = subText;

        } catch (err) {
            console.error("Hero Timer Tick Failed:", err);
        }
    }

    // --- INIT ---
    tick(); // First run immediate
    setInterval(tick, 1000);

})();

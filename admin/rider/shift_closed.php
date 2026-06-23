<?php
/**
 * Shift Closed / Locked Page for Riders
 */
// Initialize isolated rider session (JK_RIDER_SESS, 7-day lifetime)
require_once __DIR__ . '/_session_init.php';
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// If not locked and logged in, redirect home
if (!RiderCycleHelper::isLocked() && isset($_SESSION['rider_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Closed - JustKleek Rider</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --bg: #f8fafc;
            --text: #1e293b;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            text-align: center;
            color: var(--text);
        }

        .card {
            background: white;
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            width: 90%;
        }

        .icon-box {
            background: #fee2e2;
            color: #ef4444;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
            font-weight: 700;
            color: #ef4444;
        }

        p {
            margin: 0 0 24px;
            color: #64748b;
            line-height: 1.6;
        }

        .btn-logout {
            display: inline-block;
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: transform 0.2s;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
        }

        .footer-time {
            margin-top: 32px;
            font-size: 13px;
            font-weight: 600;
            color: var(--primary);
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="icon-box">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h1>Shift Currently Locked</h1>
        <p>Your shift has closed for today. All audits are finalized and new actions are disabled.<br><br><strong>Next
                shift opens at 10:00 AM.</strong></p>

        <a href="logout.php" class="btn-logout">Logout Account</a>

        <div class="footer-time">
            Current Server Time:
            <?php echo date('h:i A'); ?>
        </div>
    </div>
</body>

</html>
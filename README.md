# JustKleek — Multi-Branch Food Ordering & Delivery Platform

JustKleek is a full-stack PHP/MySQL food ordering and delivery platform built for a multi-branch restaurant business. It supports online ordering, real-time delivery tracking, rider operations, and centralized multi-branch administration — all in one system.

The platform is split into four cooperating applications that share one database:

| Role | Description |
|---|---|
| **Customer** | Browses the menu, places orders, pays online or with cash, and tracks delivery in real time. |
| **Rider** | Manages delivery shifts, accepts/delivers orders, and reconciles daily cash collections. |
| **Branch Admin (Vendor)** | Manages a single branch's menu, orders, riders, offers, and operating hours. |
| **Super Admin** | Manages all branches, admin accounts, platform-wide settings, and security monitoring. |

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [User Roles & Capabilities](#user-roles--capabilities)
  - [Customer](#1-customer)
  - [Rider](#2-rider)
  - [Branch Admin (Vendor)](#3-branch-admin-vendor)
  - [Super Admin](#4-super-admin)
- [Core Features](#core-features)
- [Payment Methods](#payment-methods)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [Security Notes](#security-notes)
- [License](#license)

---

## Architecture Overview

The application is a classic server-rendered PHP project (no framework) organized by concern:

- **`/` (root)** — Customer-facing pages: home, menu, cart, profile, order tracking.
- **`/auth`** — Customer authentication flows: signup, login, email OTP verification, Google OAuth, password reset.
- **`/app`** — Shared application logic: handlers (form/POST endpoints), functions (auth, email, location, security), and a small MVC-style `Http/Controllers` layer for newer admin features.
- **`/admin`** — Branch Admin & Super Admin dashboards, REST-ish JSON APIs (`/admin/api`), and cron-style maintenance scripts.
- **`/admin/rider`** — A separate rider-facing portal (login, dashboard, shift management, daily cash closing, delivery actions) with its own session and access guard.
- **`/api`** — Customer-facing JSON endpoints (delivery fee calculation, location updates, reverse geocoding).
- **`/esewa`** — eSewa payment gateway integration (initiate payment, success/failure callbacks).
- **`/config`** — Bootstraps database connections and loads runtime settings; never holds raw secrets directly (see [Configuration](#configuration)).
- **`/secure_config`** — The single source of truth for all credentials and API keys, kept out of version control.
- **`/includes`** — Shared view partials (navbar, footer, modals, head tags).
- **`/cron`**, **`admin/cron`** — Scheduled jobs (queue processing, rider shift finalization, offer expiry).
- **`/PHPMailer`** — Vendored PHPMailer library used for transactional email (OTP codes, verification links).

Authentication is session-based and role-aware:
- Customers use a standard session (`app/functions/auth.php`).
- Admins (Branch Admin / Super Admin) share one session namespace but are differentiated by an `admin_role` flag (`admin/includes/auth.php`), with branch-scoping enforced server-side via `getAdminBranchId()`.
- Riders use an entirely separate session and guard (`admin/rider/_guard.php`, `admin/rider/RiderContext.php`) so a compromised customer or admin session can't be used to access rider tools, and vice versa.

---

## User Roles & Capabilities

### 1. Customer

The public-facing ordering experience.

- Browse the menu by category, including combo offers and time-limited deals.
- Sign up / log in via email + OTP verification or **Google OAuth**.
- Forgot-password flow with OTP-based reset.
- Add items to cart, apply offers, and check out.
- Choose a payment method at checkout (see [Payment Methods](#payment-methods)).
- Automatic delivery fee calculation based on real distance from the kitchen (Mapbox Directions API).
- Live order tracking (map-based) from kitchen to doorstep.
- Manage profile: update details, profile picture, saved delivery location.
- Operates within per-branch operating hours (auto-detects "closed" state outside business hours).

### 2. Rider

A dedicated delivery-staff portal at `/admin/rider`, isolated from the admin dashboard.

- Secure rider login, independent of customer/admin sessions.
- Shift-based access control — the portal automatically locks between **4:00 AM–10:00 AM** for daily reconciliation and re-opens for the next shift.
- View and accept available ("grabbed") orders for delivery.
- Update live location while delivering.
- Mark orders as delivered, update payment status (cash vs. online).
- **Daily Closing** workflow: rider submits a reconciliation of cash collected, online collections, tips, and total kilometers driven for the day.
- View personal delivery history, hired distance, and earnings breakdown via dedicated audit endpoints (`get_cash_collect`, `get_tips_details`, `get_hired_km`, etc.).

### 3. Branch Admin (Vendor)

A scoped operator account tied to exactly one branch.

- Dashboard with live order feed and sales stats for their branch only.
- Manage menu items and stock availability for their branch.
- Create, edit, and expire promotional offers and combo deals.
- Manage their branch's riders (add, deactivate, review rider audits).
- Manually create orders (e.g. phone-in orders).
- Configure branch operating hours and delivery settings.
- Print kitchen receipts and customer receipts.
- View and update order status and payment status.
- Cannot see or modify data belonging to other branches — enforced server-side, not just hidden in the UI.

### 4. Super Admin

Full platform control across all branches.

- Everything a Branch Admin can do, for **any** branch, plus a global "All Branches" view.
- **Branch management**: create/edit/deactivate restaurant branches, set per-branch location, contact info, and delivery zones.
- **Admin management**: create Branch Admin accounts and assign them to specific branches.
- **Security monitoring**: dedicated security dashboard, IP unblocking, and an audit engine that tracks suspicious activity.
- Protected by a secondary **PIN verification** step (separate from the login password) for sensitive actions, with OTP-based PIN reset.
- Platform-wide sales reporting (`total_sales.php`) aggregated across branches.
- Full visibility into rider daily-closing submissions across the platform.

---

## Core Features

- **Multi-branch architecture** — every order, menu item, rider, and admin is scoped to a branch, with Super Admin able to operate across all of them.
- **Real-time delivery fee engine** — calculates delivery cost from live road distance (Mapbox), not straight-line distance.
- **Email OTP verification** — signup, login recovery, and PIN resets all use time-limited one-time codes sent via SMTP (Brevo) using PHPMailer.
- **Google OAuth login** — frictionless sign-in/sign-up with profile auto-completion for new Google users.
- **eSewa payment gateway** — HMAC-SHA256 signed payment requests and verified callbacks.
- **Rider shift system** — enforced shift windows, grabbed-order assignment, and end-of-day cash reconciliation.
- **Offer & combo engine** — time-bound promotional pricing with automatic expiry.
- **Security hardening** — centralized session config, CSRF tokens on sensitive forms, account lockout after repeated failed logins, PIN-gated super admin actions, and an audit/security-monitoring layer.

---

## Payment Methods

Customers choose a payment method on the checkout/order modal (`basket.php`). The platform supports:

| Method | Type | How it works |
|---|---|---|
| **Cash on Delivery** | Offline | Customer pays the rider in cash on delivery. Rider reconciles cash collected during their [daily closing](#2-rider). |
| **eSewa Wallet** | Online (integrated) | Full gateway integration — order is redirected to eSewa's hosted payment form, signed with an HMAC-SHA256 signature (`includes/esewa_functions.php`, `esewa/esewa_payment_start.php`), and verified on return via `esewa/success.php` / `esewa/failure.php`. |
| **PayPal (international orders)** | Manual / assisted | Shown as a banner at checkout for customers ordering on behalf of family abroad. Not a live in-checkout gateway — it links to WhatsApp so the order can be arranged and paid manually via PayPal. |
| **Fonepay** | Branding only | Logo is present in the assets (`assets/fonepay.png`) but is not currently wired into the checkout flow or any payment handler — present for future integration. |

Admins can record/update the payment status (`paid`, `unpaid`, `cash`, `online`) per order from the admin and rider dashboards (`admin/api/update_payment.php`, `admin/rider/api/update_payment.php`), regardless of which method was originally selected — useful for manually-created or corrected orders.

---

## Tech Stack

- **Backend**: PHP (procedural + light MVC layer under `app/Http/Controllers`)
- **Database**: MySQL / MariaDB (via PDO)
- **Frontend**: HTML, CSS (modular: `variables.css`, `base.css`, `navbar.css`, etc.), vanilla JavaScript
- **Email**: PHPMailer (SMTP via Brevo)
- **Payments**: eSewa (fully integrated gateway), Cash on Delivery, PayPal (manual/assisted via WhatsApp), Fonepay (branding only, not yet wired up)
- **Maps & Geolocation**: Mapbox (Directions API for delivery distance, reverse geocoding)
- **Auth**: Native PHP sessions + Google OAuth 2.0

---

## Getting Started

### Prerequisites

- PHP 8.0+
- MySQL/MariaDB
- A local server stack (XAMPP, WAMP, Laragon, or similar)
- Composer is not required — PHPMailer is vendored under `/PHPMailer`

### Setup

1. **Clone the repository** into your local server's web root (e.g. `htdocs/justkleek`).

2. **Create the database** and import the schema. If you have a local SQL dump, import it with:
   ```bash
   mysql -u root -p your_database_name < your_dump.sql
   ```

3. **Set up your secure configuration**:
   ```bash
   cp secure_config/all_security_config.example.php secure_config/all_security_config.php
   ```
   Then edit `secure_config/all_security_config.php` and fill in your own:
   - Local/production database credentials
   - SMTP credentials (for OTP and verification emails)
   - Google OAuth client ID/secret
   - eSewa merchant product code and secret key
   - Mapbox secret + public tokens

   This file is git-ignored and must **never** be committed.

4. **Configure per-branch operational settings** (optional, auto-created with defaults on first run):
   - `secure_config/restaurant_hours_branch_<id>.json`
   - `secure_config/delivery_settings_branch_<id>.json`

5. **Point your web server** at the project root and visit `index.php`.

6. **Access role-specific portals**:
   - Customer site: `/`
   - Branch Admin / Super Admin: `/admin/admin.php`
   - Rider portal: `/admin/rider/login.php`

---

## Configuration

All credentials and API keys live in a **single file outside normal version control**:

```
secure_config/all_security_config.php
```

This is intentional — `config/db.php`, `config/esewa_config.php`, `config/mapbox.php`, and `app/config/email.php` are thin legacy loaders that pull their values from this one file via `config/load_security.php`. They contain **no hardcoded secrets**.

A safe template is provided at `secure_config/all_security_config.example.php` — copy it, fill in your real values, and keep the real file local only.

In production, the recommended setup is to place the `secure_config` folder **outside the public web root** entirely (e.g. one level above `public_html`), which `getSecureConfigPath()` in `config/load_security.php` already auto-detects.

---

## Security Notes

- `.gitignore` excludes all real credential files, environment files, database dumps, debug logs, and OTP/SMTP debug artifacts.
- Never commit `secure_config/all_security_config.php` — only the `.example.php` template should be tracked.
- Rotate any credential that may have been exposed previously (database password, Google OAuth client secret, Mapbox tokens, eSewa secret key) before deploying this repository publicly.
- Admin and rider sessions are isolated from each other and from the customer session to limit cross-role session hijacking.
- Super Admin actions are additionally protected by a PIN step independent of the login password.

---

## License

This project is proprietary software for the JustKleek restaurant platform. All rights reserved unless otherwise licensed by the project owner.

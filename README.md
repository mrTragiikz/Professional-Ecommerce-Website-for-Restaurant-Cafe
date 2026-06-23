# JustKleek: Multi-Branch Food Ordering & Delivery Platform

A full-stack PHP/MySQL food ordering and delivery platform built for a multi-branch restaurant business. It supports online ordering, real-time delivery tracking, rider operations, and centralized multi-branch administration in a single system. :contentReference[oaicite:0]{index=0}

> This repository is private. It is shared here as a portfolio piece to demonstrate PHP application architecture, multi-role systems, payment integrations, and operational workflows. It is not intended for redistribution or commercial reuse.

## Platform Overview

The system is divided into four connected applications that share a single database:

| Role | Description |
|--------|--------|
| Customer | Browse menus, place orders, make payments, and track deliveries. |
| Rider | Manage deliveries, shifts, cash reconciliation, and live order updates. |
| Branch Admin | Manage a specific branch's menu, orders, riders, offers, and settings. |
| Super Admin | Manage all branches, administrators, platform settings, and security. |

## Key Features

### Customer Portal
- User registration and login
- Email OTP verification
- Google OAuth login
- Password reset via OTP
- Menu browsing by category
- Cart and checkout system
- Real-time order tracking
- Delivery fee calculation using Mapbox Directions API
- Profile management
- Saved delivery locations
- Branch operating hour detection

### Rider Portal
- Separate rider authentication system
- Shift management
- Order acceptance and delivery workflow
- Live location updates
- Delivery status management
- Cash and online payment reconciliation
- Daily closing reports
- Earnings and performance tracking

### Branch Admin Portal
- Live order dashboard
- Menu and inventory management
- Offer and combo management
- Rider management
- Manual order creation
- Operating hour configuration
- Delivery setting management
- Receipt printing
- Payment status management
- Branch-specific data access control

### Super Admin Portal
- Complete platform administration
- Multi-branch management
- Branch admin account management
- Security monitoring dashboard
- Audit and activity tracking
- PIN-protected sensitive actions
- OTP-based PIN reset
- Platform-wide sales reporting
- Rider reconciliation oversight

## Payment Methods

| Method | Type | Description |
|--------|--------|--------|
| Cash on Delivery | Offline | Customer pays rider upon delivery |
| eSewa | Online | Fully integrated payment gateway |
| PayPal | Assisted Payment | International orders handled manually through WhatsApp coordination |
| Fonepay | Future Integration | Branding available, gateway not yet implemented |

## Technical Highlights

### Backend
- PHP (Procedural + Lightweight MVC Components)
- PDO Database Layer
- Session-Based Authentication
- Role-Based Access Control

### Database
- MySQL / MariaDB

### Frontend
- HTML5
- CSS3
- Vanilla JavaScript

### Integrations
- PHPMailer
- Brevo SMTP
- Google OAuth 2.0
- Mapbox APIs
- eSewa Payment Gateway

## Security Features

- Role-based authentication
- Separate session handling for customers, riders, and admins
- CSRF protection
- Account lockout mechanisms
- Security auditing
- PIN verification for Super Admin actions
- Credential isolation
- Secure configuration management

## Project Structure

| Directory | Purpose |
|-----------|-----------|
| `/` | Customer-facing website |
| `/auth` | Authentication system |
| `/app` | Core application logic |
| `/admin` | Branch Admin and Super Admin panels |
| `/admin/rider` | Rider portal |
| `/api` | Customer APIs |
| `/esewa` | Payment gateway integration |
| `/config` | Application configuration |
| `/secure_config` | Secure credential storage |
| `/includes` | Shared components |
| `/cron` | Scheduled jobs |
| `/PHPMailer` | Email library |

## Architecture Highlights

- Multi-branch restaurant support
- Branch-level data isolation
- Real-time delivery fee calculation
- OTP-based verification workflows
- Google OAuth integration
- eSewa payment integration
- Rider shift management system
- Promotional offer engine
- Security monitoring and auditing
- Centralized administration

## Technology Stack

- PHP 8+
- MySQL / MariaDB
- HTML
- CSS
- JavaScript
- PHPMailer
- Mapbox APIs
- Google OAuth
- eSewa Payment Gateway

## Development Focus

This project demonstrates:

- Large-scale PHP application architecture
- Multi-role access management
- Payment gateway integrations
- Real-time operational workflows
- Security-focused development
- Restaurant delivery platform design
- Multi-branch business management systems
- API integration and geolocation services

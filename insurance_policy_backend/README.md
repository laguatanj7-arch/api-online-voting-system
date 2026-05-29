# Insurance Policy Management System

Vanilla PHP 8+ backend based on the online voting project structure.

## Setup

1. Import `database.sql` in phpMyAdmin or MySQL.
2. Check database credentials in `config/.env`.
3. Open `http://localhost/FINAL%20VOTING%20SYSTEM/insurance_policy_backend/`.

Seeded admin:

- Email: `admin@insurance.test`
- Password: `password123`

## Endpoints

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/users/profile`
- `PUT /api/users/profile`
- `POST /api/policies` admin only
- `GET /api/policies`
- `GET /api/policies/{policy_id}`
- `POST /api/claims`
- `GET /api/claims/{claim_id}`
- `GET /api/admin/policies` admin only
- `GET /api/admin/claims` admin only
- `GET /api/reports/claims-status` admin only
- `GET /api/reports/premium-collection` admin only

## AES-256-GCM Encryption

Encrypted fields:

- User personal data: `email`, `phone`, `address`
- Claim sensitive data: `incident_location`, `incident_description`

Encryption happens in:

- `POST /api/auth/register`
- `PUT /api/users/profile`
- `POST /api/claims`

Decryption happens in:

- `GET /api/users/profile`
- `GET /api/claims/{claim_id}`
- `GET /api/admin/claims`

Database stores ciphertext, IV, and authentication tag separately. Passwords are not encrypted; they use `password_hash()` and `password_verify()`.

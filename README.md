# Amcom API

Laravel 13 backend for the Amcom Vue frontend: buyback estates, capital investment, property marketplace and listings, Paystack payments, Cloudinary media, Zoho ZeptoMail email, plus the admin console API.

| Doc | Purpose |
|---|---|
| [docs/BACKEND_API_CONTRACT.md](docs/BACKEND_API_CONTRACT.md) | The frontend ↔ backend contract (source of truth) |
| [docs/BACKEND_ARCHITECTURE.md](docs/BACKEND_ARCHITECTURE.md) | How the backend is built: auth, RBAC, data model, payments, media, email, queues, security |
| [docs/API_IMPLEMENTATION_STATUS.md](docs/API_IMPLEMENTATION_STATUS.md) | Status of every contract endpoint |
| [docs/BACKEND_FRONTEND_INTEGRATION.md](docs/BACKEND_FRONTEND_INTEGRATION.md) | What the frontend needs to know to connect |

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
# configure DB_* (MySQL 8), and MAIL_MAILER=log for local development
php artisan migrate --seed
php artisan serve          # http://localhost:8000/api/v1
php artisan queue:work     # emails, Paystack webhooks, Cloudinary cleanup
php artisan schedule:work  # plot reservations, maturities, reminders
```

Demo logins (non-production seed, password `Password123!`): `user@amcom.test`, `new@amcom.test`, `admin@amcom.test`, `superadmin@amcom.test`.

## Quality checks

```bash
php artisan test               # feature + unit tests (external services faked)
vendor/bin/pint --test         # code style
vendor/bin/phpstan analyse     # Larastan level 5
```

## Configuration

All credentials come from the environment. See `.env.example` for Cloudinary, Paystack, ZeptoMail, Persona, Termii, Google OAuth, CORS (`FRONTEND_URLS`) and queue settings. No secrets are committed.

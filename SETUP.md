# WhatsApp CRM — Setup & Developer Guide

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Prerequisites](#prerequisites)
3. [Project Structure](#project-structure)
4. [Installation — Laravel CRM](#installation--laravel-crm)
5. [Installation — WhatsApp Gateway](#installation--whatsapp-gateway)
6. [Configuration Reference](#configuration-reference)
7. [Running the Application](#running-the-application)
8. [First Login & WhatsApp Pairing](#first-login--whatsapp-pairing)
9. [Role & Permission System](#role--permission-system)
10. [Message Flow Explained](#message-flow-explained)
11. [Document Storage & Encryption](#document-storage--encryption)
12. [Real-Time Events (Laravel Reverb)](#real-time-events-laravel-reverb)
13. [Queue & Rate Limiting](#queue--rate-limiting)
14. [Security Considerations](#security-considerations)
15. [Production Deployment](#production-deployment)
16. [Upgrading to WhatsApp Cloud API](#upgrading-to-whatsapp-cloud-api)
17. [Troubleshooting](#troubleshooting)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Browser / CRM Users                       │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTPS  (Inertia SSR/SPA)
┌────────────────────────────▼────────────────────────────────────┐
│              Laravel 11  (PHP 8.2+)                              │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────┐   │
│  │  Inertia    │  │  Controllers │  │  Events / Broadcast  │   │
│  │  Vue 3 SPA  │  │  (REST API)  │  │  (Laravel Reverb WS) │   │
│  └─────────────┘  └──────────────┘  └──────────────────────┘   │
│         │                │                      │                │
│  ┌──────▼──────────────────────────────────────▼──────────────┐ │
│  │              MySQL  ·  Redis  ·  Encrypted File Storage     │ │
│  └────────────────────────────────────────────────────────────┘ │
└──────────────────────────────┬──────────────────────────────────┘
                               │ HTTP  (secret header auth)
┌──────────────────────────────▼──────────────────────────────────┐
│            WhatsApp Gateway  (Node.js + Express)                  │
│  ┌──────────────────┐   ┌────────────────┐   ┌───────────────┐  │
│  │  whatsapp-web.js │   │  Bull Queue    │   │  Rate Limiter │  │
│  │  (Puppeteer)     │   │  (Redis-backed)│   │  (4/min)      │  │
│  └──────────────────┘   └────────────────┘   └───────────────┘  │
└──────────────────────────────┬──────────────────────────────────┘
                               │  WhatsApp Web Protocol
                         WhatsApp Servers
```

### Key Design Decisions
- **Single WhatsApp session** on the gateway — all messages funnel through one number.
- **Gateway ↔ CRM** communicate via HTTP with a shared secret header (`X-Gateway-Secret`).
- **Outbound messages** are queued in Bull (Redis-backed) with rate limiting (≤4/min) and randomized delays (3–12s) to mimic human behaviour.
- **Documents** are stored AES-encrypted on disk (Laravel's `Crypt::encryptString`).
- **Real-time updates** use Laravel Reverb (WebSocket server built into Laravel).

---

## Prerequisites

| Tool        | Minimum Version | Notes                              |
|-------------|----------------|------------------------------------|
| PHP         | 8.2            | With extensions: pdo, mbstring, openssl, redis |
| Composer    | 2.x            |                                    |
| Node.js     | 20 LTS         | Required for both Vite and Gateway |
| npm         | 10+            |                                    |
| MySQL       | 8.0+           | Or MariaDB 10.6+                   |
| Redis       | 6+             | Used for cache, queue, sessions    |
| Chromium    | Latest         | Required by Puppeteer (whatsapp-web.js) |

---

## Project Structure

```
crm-whatsapp/
├── app/
│   ├── Events/              # Broadcast events (NewMessageReceived, etc.)
│   ├── Http/
│   │   ├── Controllers/     # All Laravel controllers
│   │   └── Middleware/      # HandleInertiaRequests
│   ├── Models/              # Eloquent models
│   ├── Policies/            # Authorization policies
│   └── Services/            # GatewayService, DocumentService, AuditService
├── bootstrap/app.php        # Laravel 11 bootstrap
├── config/whatsapp.php      # Gateway URL + secret config
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── css/app.css          # Tailwind styles
│   ├── js/
│   │   ├── app.js           # Inertia entry point
│   │   ├── Components/      # Vue components (Layout, Chat, UI, Icons)
│   │   ├── Composables/     # useEcho, useToast
│   │   ├── Pages/           # Inertia page components
│   │   └── Stores/          # Pinia stores
│   └── views/app.blade.php
├── routes/
│   ├── api.php              # Gateway webhook endpoint
│   ├── auth.php             # Login/logout
│   ├── channels.php         # Broadcast channel auth
│   └── web.php              # All CRM routes
├── whatsapp-gateway/        # Node.js WhatsApp service
│   ├── src/
│   │   ├── index.js         # Express + whatsapp-web.js
│   │   ├── queue.js         # Bull queue configuration
│   │   └── logger.js        # Winston logger
│   └── package.json
├── .env.example
└── SETUP.md
```

---

## Installation — Laravel CRM

### Step 1 — Clone & install PHP dependencies

```bash
cd crm-whatsapp
composer install
```

### Step 2 — Environment setup

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure:
- `DB_*`  — your MySQL credentials
- `REDIS_*` — Redis connection
- `REVERB_*` — keep defaults for local dev
- `WHATSAPP_GATEWAY_URL` and `WHATSAPP_GATEWAY_SECRET`

### Step 3 — Database

```bash
php artisan migrate
php artisan db:seed
```

This creates:
- `admin@crm.test` / `password`  → Admin role
- `sarah@crm.test` / `password`  → Executive role
- `raj@crm.test`   / `password`  → Executive role
- 10 sample customers

### Step 4 — Install frontend dependencies & build

```bash
npm install
npm run dev      # Development (with HMR)
# OR
npm run build    # Production build
```

### Step 5 — Publish Spatie permissions config

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

---

## Installation — WhatsApp Gateway

### Step 1 — Install dependencies

```bash
cd whatsapp-gateway
npm install
```

> **Note:** `whatsapp-web.js` uses Puppeteer which downloads Chromium automatically (~170MB). Ensure internet access during install. On servers, pass `--no-sandbox` (already configured in `index.js`).

### Step 2 — Environment setup

```bash
cp .env.example .env
```

Edit `whatsapp-gateway/.env`:
```env
GATEWAY_PORT=3001
GATEWAY_SECRET=super_secret_gateway_key_change_this  # Must match Laravel .env

CRM_URL=http://localhost:8000
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
```

### Step 3 — Create required directories

```bash
mkdir -p logs temp_uploads
```

### Step 4 — Start the gateway

```bash
npm start
# OR for development with auto-restart:
npm run dev
```

On first run, a QR code will be printed in the terminal. Scan it with WhatsApp to link the session.

---

## Configuration Reference

### `config/whatsapp.php`

| Key              | Env Variable                  | Description                          |
|-----------------|-------------------------------|--------------------------------------|
| `gateway_url`   | `WHATSAPP_GATEWAY_URL`       | Base URL of the Node.js gateway      |
| `gateway_secret`| `WHATSAPP_GATEWAY_SECRET`    | Shared secret for webhook auth       |

### Queue rate limit (gateway)
Edit `whatsapp-gateway/src/queue.js`:
```js
limiter: {
    max: 4,         // messages per window
    duration: 60000 // window in ms (60 seconds)
}
```

### Human-like delay (gateway `index.js` — `send` endpoint)
```js
const delay_ms = (3000 + Math.floor(Math.random() * 9000)); // 3–12 seconds
```
Adjust to your preference. Higher values = safer but slower.

---

## Running the Application

You need **4 processes** running concurrently. Use separate terminals or a process manager like `tmux` / `PM2` / `Supervisor`.

```bash
# Terminal 1 — Laravel dev server
php artisan serve

# Terminal 2 — Vite dev server (HMR)
npm run dev

# Terminal 3 — Laravel Reverb (WebSocket)
php artisan reverb:start

# Terminal 4 — WhatsApp Gateway
cd whatsapp-gateway && npm start
```

Open `http://localhost:8000` in your browser.

---

## First Login & WhatsApp Pairing

1. Open `http://localhost:8000/login`
2. Sign in with `admin@crm.test` / `password`
3. Navigate to **Dashboard** — you'll see the WhatsApp status in the sidebar
4. If status shows **"Scan QR Code"**, open WhatsApp on your phone:
   - **WhatsApp** → **Settings** → **Linked Devices** → **Link a Device**
   - Scan the QR code shown in the sidebar or dashboard
5. Once connected, the status changes to **"Connected: +91XXXXXXXXXX"**
6. Test by sending a WhatsApp message from any number to the linked number — it should appear in real time in the CRM under the matching customer

---

## Role & Permission System

Three roles are pre-seeded using **Spatie Laravel Permission**:

| Role       | Dashboard | Customers     | Admin Panel | Audit Logs |
|------------|-----------|---------------|-------------|------------|
| `admin`    | All       | All           | ✅           | ✅          |
| `executive`| Own       | Assigned only | ❌           | ❌          |
| `auditor`  | All (read)| All (read)    | ❌           | ✅          |

### Adding custom permissions
```bash
php artisan tinker
> \Spatie\Permission\Models\Permission::create(['name' => 'export.reports']);
> User::find(1)->givePermissionTo('export.reports');
```

---

## Message Flow Explained

### Inbound (Customer → CRM)

```
Customer sends WhatsApp message
    ↓
whatsapp-web.js 'message' event fires
    ↓
Gateway: downloads media (if any), extracts metadata
    ↓
Gateway: POST /api/gateway/webhook → Laravel (with X-Gateway-Secret)
    ↓
GatewayService::handleIncomingMessage()
    ├─ Finds or creates Customer by phone number
    ├─ Creates Message record (direction: inbound)
    ├─ If media: DocumentService::saveFromWhatsApp() → encrypted storage
    └─ Broadcasts NewMessageReceived event via Reverb
         ↓
Executive browser receives real-time push → ChatWindow updates instantly
```

### Outbound (CRM → Customer)

```
Executive types message → clicks Send
    ↓
Vue ChatWindow: optimistic UI update
    ↓
POST /customers/{id}/messages → MessageController::send()
    ├─ Creates Message record (status: pending)
    ├─ Checks gateway is connected
    └─ POST http://gateway:3001/send (with secret)
         ↓
Gateway: adds job to Bull queue with randomized delay
    ↓
Bull processes job (respecting 4/min rate limit)
    ↓
whatsapp-web.js sends message
    ↓
Gateway: POST /api/gateway/webhook (event: message_sent)
    ↓
Message status updated to 'sent' → broadcast to browser
```

---

## Document Storage & Encryption

All documents (whether received via WhatsApp or manually uploaded) are:
1. **Decoded** from base64 (WhatsApp) or read from temp upload (manual)
2. **Encrypted** using Laravel's `Crypt::encryptString()` (AES-256-CBC)
3. **Stored** at `storage/app/documents/{customer_id}/{year}/{month}/{uuid}.ext`

To **retrieve** a document, the `DocumentService::getDecryptedContent()` method decrypts on the fly during download. Raw encrypted files are never exposed publicly.

> **Important:** The encryption key is derived from `APP_KEY` in `.env`. Back up your `APP_KEY` — losing it means losing access to all stored documents.

---

## Real-Time Events (Laravel Reverb)

| Channel           | Event               | Payload                          | Who receives     |
|-------------------|---------------------|----------------------------------|------------------|
| `messages`        | `message.received`  | Message + customer + document    | All logged-in    |
| `messages`        | `message.status`    | `{job_id, status}`               | All logged-in    |
| `user.{id}`       | `message.received`  | Same as above                    | Assigned exec    |
| `whatsapp-status` | `status.changed`    | `{status, qr}`                   | All logged-in    |

The Vue `useChannel` composable (in `Composables/useEcho.js`) wraps Echo subscriptions and automatically cleans up on component unmount.

---

## Queue & Rate Limiting

The gateway uses **Bull** (Redis-backed) with built-in rate limiting:

```js
// whatsapp-gateway/src/queue.js
limiter: { max: 4, duration: 60000 }  // 4 messages per minute
```

Each job also has a randomized delay applied **before** sending:
```js
// index.js POST /send
const delay_ms = (3000 + Math.floor(Math.random() * 9000)); // 3–12s
```

Jobs are retried up to **3 times** with exponential backoff on failure. Queue stats (waiting / active / completed / failed) are available via `GET /gateway/queue/stats`.

---

## Security Considerations

### Gateway webhook authentication
Every request from the gateway to Laravel includes `X-Gateway-Secret`. The `GatewayController` validates this against `WHATSAPP_GATEWAY_SECRET` before processing any payload.

### CSRF exemption
The `/api/gateway/webhook` route is intentionally excluded from CSRF protection (it's a server-to-server call authenticated by the secret header).

### Document access control
Document downloads go through `DocumentController::download()` which calls `$this->authorize('view', $document->customer)` — enforcing that only the assigned executive (or admin) can download.

### Rate limiting
Add `throttle` middleware to sensitive routes in production:
```php
Route::middleware(['auth', 'throttle:60,1'])->group(...);
```

### Session security
Use `SESSION_DRIVER=database` (already set in `.env.example`) and set `SESSION_SECURE_COOKIE=true` behind HTTPS.

---

## Production Deployment

### 1. Server requirements
- Ubuntu 22.04+ / Debian 12
- Nginx + PHP-FPM
- MySQL 8.0
- Redis 7
- Node.js 20 LTS
- Chromium (for Puppeteer): `apt install chromium-browser`

### 2. Laravel production setup

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit .env: APP_ENV=production, APP_DEBUG=false, correct DB/Redis creds

php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

npm ci
npm run build
```

### 3. Supervisor configuration

Create `/etc/supervisor/conf.d/crm.conf`:

```ini
[program:crm-queue]
command=php /var/www/crm/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/crm-queue.log

[program:crm-reverb]
command=php /var/www/crm/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm-reverb.log

[program:whatsapp-gateway]
command=node /var/www/crm/whatsapp-gateway/src/index.js
directory=/var/www/crm/whatsapp-gateway
autostart=true
autorestart=true
user=www-data
environment=NODE_ENV=production
redirect_stderr=true
stdout_logfile=/var/log/whatsapp-gateway.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start all
```

### 4. Nginx configuration

```nginx
server {
    listen 80;
    server_name your-crm-domain.com;
    root /var/www/crm/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 5. Storage link

```bash
php artisan storage:link
```

**Important:** Do NOT create a symlink for the `documents` folder — it must remain private and only served through the encrypted download controller.

---

## Upgrading to WhatsApp Cloud API

When you're ready to migrate away from `whatsapp-web.js` to the official **Meta WhatsApp Cloud API**:

1. **Replace the gateway** — The Node.js gateway is intentionally isolated. Swap `whatsapp-web.js` calls in `src/index.js` with calls to `https://graph.facebook.com/v19.0/{phone-id}/messages`.
2. **Webhook format** — Meta sends webhooks to a public HTTPS URL. Update `GatewayController::webhook()` to handle the Cloud API payload format and verify with `X-Hub-Signature-256`.
3. **Media handling** — Cloud API returns a media ID; retrieve media via `GET /{media-id}` before storing.
4. **Message templates** — Cloud API requires pre-approved templates for the first contact. Adjust your outbound flow accordingly.
5. **No QR code** — Replace the QR pairing flow with OAuth + WABA registration in the Meta dashboard.

All CRM logic, models, and frontend remain identical — only the gateway changes.

---

## Troubleshooting

### Gateway QR code not appearing
- Ensure Redis is running: `redis-cli ping` → `PONG`
- Check gateway logs: `cat whatsapp-gateway/logs/combined.log`
- Ensure Chromium/Puppeteer can run: `node -e "const p = require('puppeteer'); p.launch({args:['--no-sandbox']}).then(b => { console.log('OK'); b.close(); })"`

### Messages not appearing in real time
- Confirm Reverb is running: `php artisan reverb:start`
- Check browser console for WebSocket errors
- Verify `VITE_REVERB_*` values in `.env` match `REVERB_*` values

### "WhatsApp is not connected" error when sending
- The gateway status endpoint is polled every 30s. Check `GET /gateway/status`
- Manually trigger status refresh by reloading the dashboard

### WhatsApp session drops frequently
- The gateway auto-reconnects after 5 seconds on disconnect
- If using a VPS, ensure it has a stable internet connection
- Avoid running the same WhatsApp number on a phone and the gateway simultaneously

### Document download returns 500
- Ensure `APP_KEY` in `.env` hasn't changed since documents were stored
- Check `storage/logs/laravel.log` for decryption errors
- Verify file exists: `php artisan tinker` → `Storage::exists($document->path)`

### Queue jobs piling up
- Check Redis: `redis-cli llen whatsapp-messages:wait`
- Ensure queue worker is running: `php artisan queue:work` (or Supervisor in production)
- Check gateway `/queue/stats` endpoint for Bull queue health

---
layout: default
title: Ubuntu
nav_order: 2
permalink: /deploy-ubuntu
description: >
  Full manual deployment of logstream-server on Ubuntu 22.04 or 24.04 LTS
  using PHP 8.3, systemd, Nginx, and Certbot.
---

# Deploying logstream-server on Ubuntu

This guide covers a full manual deployment of the logstream-server on a fresh Ubuntu server (22.04 or 24.04 LTS), from a bare machine through to a running HTTPS service with Nginx and a systemd daemon.

---

## Prerequisites

- A Ubuntu 22.04 or 24.04 VPS or dedicated server
- A domain name with an **A record** pointing to the server's public IP
- SSH access with `sudo` privileges
- The repository URL: `https://github.com/guibranco/logstream-server`

---

## 1. System update

Always start with a full system update before installing anything:

```bash
sudo apt update && sudo apt upgrade -y
```

---

## 2. Install PHP 8.3

Ubuntu's default repositories ship older PHP versions. Use the Ondrej PPA which provides all PHP versions:

```bash
# Install prerequisite tools
sudo apt install -y software-properties-common curl

# Add the Ondrej PHP PPA
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP 8.3 CLI and all required extensions
sudo apt install -y \
    php8.3-cli \
    php8.3-sockets \
    php8.3-mysql \
    php8.3-xml \
    php8.3-mbstring \
    php8.3-curl \
    php8.3-zip

# Verify the installation
php8.3 --version
```

---

## 3. Install Composer

```bash
# Download and install Composer globally
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php8.3 /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Verify
composer --version
```

---

## 4. Install Git

```bash
sudo apt install -y git

# Verify
git --version
```

---

## 5. Create a dedicated system user

Running the service as a dedicated user prevents it from having unnecessary access to the rest of the system:

```bash
sudo useradd --system --shell /usr/sbin/nologin logstream

# Create a home directory so Composer can write its cache
sudo mkdir -p /home/logstream
sudo chown -R logstream:logstream /home/logstream
sudo usermod -d /home/logstream logstream
```

---

## 6. Clone the repository

```bash
# Create the application directory
sudo mkdir -p /opt/logstream-server

# Clone the repository
sudo git clone https://github.com/guibranco/logstream-server.git /opt/logstream-server

# Hand ownership to the service user
sudo chown -R logstream:logstream /opt/logstream-server
```

> **Private repository?** Generate a deploy key instead:
> ```bash
> sudo -u logstream ssh-keygen -t ed25519 -C "deploy@your-server" \
>     -f /home/logstream/.ssh/deploy_key -N ""
> # Print the public key and add it to GitHub → Settings → Deploy keys
> sudo cat /home/logstream/.ssh/deploy_key.pub
> # Then clone with:
> sudo -u logstream GIT_SSH_COMMAND='ssh -i /home/logstream/.ssh/deploy_key' \
>     git clone git@github.com:guibranco/logstream-server.git /opt/logstream-server
> ```

---

## 7. Configure the environment

```bash
cd /opt/logstream-server

# Copy the example env file
sudo cp .env.example .env

# Edit it with your values
sudo nano .env
```

Key values to set:

```env
HTTP_PORT=8081
WS_PORT=8080

# Write key — used by your applications to POST logs
API_SECRET=<generate-a-strong-secret>

# Read key — used by the UI and authorised humans to view logs
UI_SECRET=<generate-another-strong-secret>

# Storage: "file" needs no extra setup; "mariadb" requires the DB section below
STORAGE_TYPE=file
LOG_PATH=./storage/logs
```

Generate strong secrets with:

```bash
openssl rand -base64 32
```

Restrict access to the env file:

```bash
sudo chmod 640 /opt/logstream-server/.env
sudo chown logstream:logstream /opt/logstream-server/.env
```

---

## 8. Install PHP dependencies

```bash
cd /opt/logstream-server

sudo -u logstream composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress
```

---

## 9. Create the log storage directory

Only needed when using `STORAGE_TYPE=file`:

```bash
sudo mkdir -p /opt/logstream-server/storage/logs
sudo chown -R logstream:logstream /opt/logstream-server/storage
```

---

## 10. (Optional) MariaDB setup

Skip this section if you are using `STORAGE_TYPE=file`.

### Install MariaDB

```bash
sudo apt install -y mariadb-server
sudo systemctl enable --now mariadb
sudo mysql_secure_installation
```

### Create the database and user

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE logservice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'logservice'@'127.0.0.1' IDENTIFIED BY 'a-strong-db-password';
GRANT ALL PRIVILEGES ON logservice.* TO 'logservice'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

### Run the migration

```bash
mysql -h 127.0.0.1 -u logservice -p logservice \
    < /opt/logstream-server/migrations/001_logs.sql
```

### Update the `.env` file

```env
STORAGE_TYPE=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=logservice
DB_USER=logservice
DB_PASS=a-strong-db-password
```

---

## 11. Create the systemd service

```bash
sudo nano /etc/systemd/system/logstream-server.service
```

Paste the following:

```ini
[Unit]
Description=LogStream Server (HTTP + WebSocket)
Documentation=https://github.com/guibranco/logstream-server
After=network.target
# Uncomment the next line if using MariaDB storage:
# After=network.target mariadb.service

[Service]
Type=simple
User=logstream
Group=logstream
WorkingDirectory=/opt/logstream-server

ExecStart=/usr/bin/php8.3 bin/server.php
Restart=on-failure
RestartSec=5s
TimeoutStopSec=10

StandardOutput=journal
StandardError=journal
SyslogIdentifier=logstream-server

[Install]
WantedBy=multi-user.target
```

> **Note:** The `PrivateTmp` and `ProtectSystem` hardening options are intentionally omitted here. They require kernel namespace support which is unavailable on many VPS providers (OpenVZ, LXC). If your host supports it you can add them back.

### Enable and start the service

```bash
# Reload systemd so it sees the new unit file
sudo systemctl daemon-reload

# Enable the service to start automatically on reboot
sudo systemctl enable logstream-server

# Start the service now
sudo systemctl start logstream-server

# Check the status
sudo systemctl status logstream-server
```

You should see output similar to:

```
[Storage] File (./storage/logs)
╔══════════════════════════════════════════╗
║           LogService started             ║
╠══════════════════════════════════════════╣
║  HTTP API  →  http://0.0.0.0:8081       ║
║  WebSocket →  ws://0.0.0.0:8080         ║
╠══════════════════════════════════════════╣
║  Write key (API_SECRET)  : ✅ set       ║
║  Read key  (UI_SECRET)   : ✅ set       ║
╚══════════════════════════════════════════╝
```

### Quick local health check

```bash
curl -s http://localhost:8081/api/health | python3 -m json.tool
```

---

## 12. Install and configure Nginx

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

### Create the site config

Replace `logs.yourdomain.com` with your actual domain throughout:

```bash
sudo nano /etc/nginx/sites-available/logs.yourdomain.com
```

Paste the following HTTP-only config first (SSL will be added by Certbot in the next step):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name logs.yourdomain.com;

    location /api/ {
        proxy_pass         http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Host            $host;
        proxy_set_header X-Real-IP       $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /ws {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Enable the site

```bash
sudo ln -s /etc/nginx/sites-available/logs.yourdomain.com \
           /etc/nginx/sites-enabled/

# Remove the default placeholder site
sudo rm -f /etc/nginx/sites-enabled/default

# Test and reload
sudo nginx -t && sudo systemctl reload nginx
```

---

## 13. Obtain an SSL certificate with Certbot

> Make sure the domain's DNS A record is already pointing to this server before running Certbot.

```bash
sudo apt install -y certbot python3-certbot-nginx

# Obtain and install the certificate
# Choose option 2 (Redirect) when prompted about HTTP traffic
sudo certbot --nginx -d logs.yourdomain.com
```

Certbot will automatically rewrite the Nginx config with the certificate paths and an HTTP→HTTPS redirect.

### Add WebSocket and security headers to the HTTPS block

Certbot only patches the basic proxy block. Open the config and add the missing pieces to the `listen 443` server block:

```bash
sudo nano /etc/nginx/sites-available/logs.yourdomain.com
```

The final complete config should look like this:

```nginx
# HTTP → HTTPS redirect (written by Certbot)
server {
    listen 80;
    listen [::]:80;
    server_name logs.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name logs.yourdomain.com;

    # ── SSL — managed by Certbot ─────────────────────────────────────────────
    ssl_certificate     /etc/letsencrypt/live/logs.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/logs.yourdomain.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # ── Security headers ─────────────────────────────────────────────────────
    add_header X-Frame-Options        "SAMEORIGIN"    always;
    add_header X-Content-Type-Options "nosniff"       always;
    add_header X-XSS-Protection       "1; mode=block" always;
    add_header Referrer-Policy        "no-referrer"   always;

    # ── HTTP API → :8081 ─────────────────────────────────────────────────────
    location /api/ {
        proxy_pass         http://127.0.0.1:8081;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_read_timeout    60s;
        proxy_send_timeout    60s;
        proxy_connect_timeout 10s;
    }

    # ── WebSocket → :8080 ────────────────────────────────────────────────────
    location /ws {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;

        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_set_header Host            $host;
        proxy_set_header X-Real-IP       $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # WebSocket connections are long-lived
        proxy_read_timeout  3600s;
        proxy_send_timeout  3600s;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Verify certificate auto-renewal

```bash
# Check the renewal timer is active
sudo systemctl status certbot.timer

# Dry-run a renewal to confirm it works
sudo certbot renew --dry-run
```

---

## 14. Harden the firewall

Now that Nginx is the public entry point, close the raw PHP ports:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw deny 8081/tcp
sudo ufw deny 8080/tcp
sudo ufw enable

# Confirm rules
sudo ufw status verbose
```

---

## 15. Final end-to-end verification

```bash
# 1. Health check over HTTPS
curl -s https://logs.yourdomain.com/api/health | python3 -m json.tool

# 2. Confirm HTTP redirects to HTTPS
curl -sI http://logs.yourdomain.com/api/health

# 3. Send a test log entry
curl -s -X POST https://logs.yourdomain.com/api/logs \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer <API_SECRET>" \
    -H "User-Agent: DeployTest/1.0" \
    -d '{
        "app_key":  "deploy-test",
        "app_id":   "production",
        "level":    "info",
        "category": "deployment",
        "message":  "Server deployed successfully"
    }' | python3 -m json.tool

# 4. Read it back (uses the UI_SECRET)
curl -s "https://logs.yourdomain.com/api/logs?app_key=deploy-test" \
    -H "Authorization: Bearer <UI_SECRET>" | python3 -m json.tool
```

---

## Day-to-day operations

### View live logs

```bash
sudo journalctl -u logstream-server -f
```

### Restart the service

```bash
sudo systemctl restart logstream-server
```

### Pull and deploy a new version manually

```bash
cd /opt/logstream-server

sudo -u logstream git pull origin main

sudo -u logstream composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

sudo systemctl restart logstream-server

# Confirm healthy
curl -s http://localhost:8081/api/health | python3 -m json.tool
```

### Run database migrations (MariaDB only)

```bash
cd /opt/logstream-server
chmod +x Tools/db-migration.sh
Tools/db-migration.sh migrations 127.0.0.1 logservice logservice
```

### Check certificate expiry

```bash
sudo certbot certificates
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `226/NAMESPACE` in systemd logs | VPS does not support kernel namespaces (OpenVZ/LXC) | Remove `PrivateTmp`, `ProtectSystem`, `NoNewPrivileges` from the unit file |
| `composer: command not found` | Composer not installed globally | Repeat step 3 |
| `zip extension missing` during `composer install` | PHP zip extension missing | `sudo apt install -y php8.3-zip unzip` |
| `could not create leading directories` during `composer install` | `logstream` user has no home directory | Repeat the home directory creation in step 5 |
| Nginx `cannot load certificate` on `nginx -t` | Certificate does not exist yet | Run Certbot first (step 13), then add SSL directives |
| `401 Unauthorized` on `POST /api/logs` | Wrong or missing `API_SECRET` | Check `Authorization: Bearer <API_SECRET>` header |
| `401 Unauthorized` on `GET /api/logs` | Wrong or missing `UI_SECRET` | Check `Authorization: Bearer <UI_SECRET>` header |
| WebSocket connection rejected immediately | Wrong or missing token | Connect with `wss://domain/ws?token=<UI_SECRET>` |
| Port 8081/8080 unreachable from outside | UFW blocking or Nginx not proxying | Verify Nginx config and `sudo ufw status` |

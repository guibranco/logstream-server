---
layout: default
title: Windows
nav_order: 3
permalink: /deploy-windows
description: >
  Full manual deployment of logstream-server on Windows 10/11 or
  Windows Server 2019/2022 using PHP 8.3 NTS and NSSM.
---

# Deploying logstream-server on Windows

This guide covers a full manual deployment of the logstream-server on a Windows machine (Windows 10/11 or Windows Server 2019/2022), running as a background Windows Service using NSSM.

---

## Prerequisites

- Windows 10/11 or Windows Server 2019/2022 (64-bit)
- Administrator privileges
- The repository URL: `https://github.com/guibranco/logstream-server`
- A domain name with an **A record** pointing to your server's public IP (for HTTPS)
- Ports **8080** and **8081** available (or configure alternates in `.env`)

---

## 1. Install PHP 8.3

### Download

Go to [https://windows.php.net/download](https://windows.php.net/download) and download the **PHP 8.3 Non-Thread Safe (NTS) x64** ZIP archive.

> Use the **NTS** build — the service runs a single long-lived CLI process, not Apache/IIS.

### Install

```powershell
# Run PowerShell as Administrator

# Create the PHP directory
New-Item -ItemType Directory -Path "C:\php8.3" -Force

# Extract the downloaded ZIP to C:\php8.3
Expand-Archive -Path "$env:USERPROFILE\Downloads\php-8.3.x-nts-Win32-vs16-x64.zip" `
               -DestinationPath "C:\php8.3"
```

### Configure php.ini

```powershell
# Copy the example ini file
Copy-Item "C:\php8.3\php.ini-production" "C:\php8.3\php.ini"

# Open it for editing
notepad "C:\php8.3\php.ini"
```

Find and uncomment (remove the `;`) the following lines:

```ini
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=sockets
extension=zip
```

Also set the timezone:

```ini
date.timezone = Europe/Dublin
```

### Add PHP to the system PATH

```powershell
# Add C:\php8.3 to the system PATH permanently
[Environment]::SetEnvironmentVariable(
    "PATH",
    $env:PATH + ";C:\php8.3",
    [EnvironmentVariableTarget]::Machine
)
```

Close and reopen PowerShell, then verify:

```powershell
php --version
```

---

## 2. Install Composer

### Download and install

```powershell
# Download the Composer installer
Invoke-WebRequest -Uri "https://getcomposer.org/Composer-Setup.exe" `
                  -OutFile "$env:TEMP\Composer-Setup.exe"

# Run the installer (GUI — follow the prompts, point it to C:\php8.3\php.exe)
Start-Process "$env:TEMP\Composer-Setup.exe" -Wait
```

The installer adds Composer to the PATH automatically.

Verify:

```powershell
composer --version
```

---

## 3. Install Git

Download and install Git for Windows from [https://git-scm.com/download/win](https://git-scm.com/download/win).

Accept all defaults during installation.

Verify:

```powershell
git --version
```

---

## 4. Clone the repository

```powershell
# Run PowerShell as Administrator

# Create the application directory
New-Item -ItemType Directory -Path "C:\logstream-server" -Force

# Clone the repository
git clone https://github.com/guibranco/logstream-server.git C:\logstream-server
```

> **Private repository?** Configure a personal access token:
> ```powershell
> git clone https://<YOUR_TOKEN>@github.com/guibranco/logstream-server.git C:\logstream-server
> ```

---

## 5. Configure the environment

```powershell
# Copy the example env file
Copy-Item "C:\logstream-server\.env.example" "C:\logstream-server\.env"

# Open it for editing
notepad "C:\logstream-server\.env"
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
LOG_PATH=C:\logstream-server\storage\logs
```

Generate strong secrets in PowerShell:

```powershell
[System.Web.Security.Membership]::GeneratePassword(32, 4)
# or
-join ((65..90) + (97..122) + (48..57) | Get-Random -Count 32 | % {[char]$_})
```

---

## 6. Install PHP dependencies

```powershell
cd C:\logstream-server

composer install --no-dev --optimize-autoloader --no-interaction --no-progress
```

---

## 7. Create the log storage directory

Only needed when using `STORAGE_TYPE=file`:

```powershell
New-Item -ItemType Directory -Path "C:\logstream-server\storage\logs" -Force
```

---

## 8. (Optional) MySQL / MariaDB setup

Skip this section if you are using `STORAGE_TYPE=file`.

### Install MariaDB

Download MariaDB from [https://mariadb.org/download](https://mariadb.org/download) and run the MSI installer. Note the root password you set during installation.

### Create the database and user

Open the MariaDB command prompt (Start → MariaDB → MySQL Client):

```sql
CREATE DATABASE logservice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'logservice'@'127.0.0.1' IDENTIFIED BY 'a-strong-db-password';
GRANT ALL PRIVILEGES ON logservice.* TO 'logservice'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

### Run the migration

```powershell
mysql -h 127.0.0.1 -u logservice -p logservice `
    < C:\logstream-server\migrations\001_logs.sql
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

## 9. Test the server manually first

Before installing as a service, confirm the server starts:

```powershell
cd C:\logstream-server
php bin\server.php
```

You should see:

```
[Storage] File (C:\logstream-server\storage\logs)
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

In a second PowerShell window, verify the health endpoint:

```powershell
Invoke-RestMethod http://localhost:8081/api/health | ConvertTo-Json
```

Press `Ctrl+C` to stop the server before continuing.

---

## 10. Install NSSM (Non-Sucking Service Manager)

NSSM wraps any executable as a proper Windows Service with restart-on-failure support.

```powershell
# Download NSSM
Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" `
                  -OutFile "$env:TEMP\nssm.zip"

# Extract it
Expand-Archive -Path "$env:TEMP\nssm.zip" -DestinationPath "$env:TEMP\nssm"

# Copy the 64-bit binary to a permanent location
New-Item -ItemType Directory -Path "C:\tools" -Force
Copy-Item "$env:TEMP\nssm\nssm-2.24\win64\nssm.exe" "C:\tools\nssm.exe"

# Add C:\tools to the system PATH
[Environment]::SetEnvironmentVariable(
    "PATH",
    $env:PATH + ";C:\tools",
    [EnvironmentVariableTarget]::Machine
)
```

Reopen PowerShell and verify:

```powershell
nssm version
```

---

## 11. Register logstream-server as a Windows Service

```powershell
# Run PowerShell as Administrator

# Register the service
nssm install logstream-server "C:\php8.3\php.exe"

# Set startup arguments
nssm set logstream-server AppParameters "C:\logstream-server\bin\server.php"

# Set the working directory (important for .env loading)
nssm set logstream-server AppDirectory "C:\logstream-server"

# Redirect stdout and stderr to log files
nssm set logstream-server AppStdout "C:\logstream-server\storage\service-stdout.log"
nssm set logstream-server AppStderr "C:\logstream-server\storage\service-stderr.log"
nssm set logstream-server AppRotateFiles 1
nssm set logstream-server AppRotateBytes 10485760

# Restart automatically on failure, after 5 seconds
nssm set logstream-server AppThrottle 5000
nssm set logstream-server AppRestartDelay 5000

# Set the service description
nssm set logstream-server Description "LogStream Server — HTTP API and WebSocket log service"

# Start the service
nssm start logstream-server

# Confirm it is running
nssm status logstream-server
```

### Configure the service to start automatically

```powershell
Set-Service -Name logstream-server -StartupType Automatic
```

---

## 12. Managing the service

```powershell
# Start
nssm start logstream-server

# Stop
nssm stop logstream-server

# Restart
nssm restart logstream-server

# View current status
nssm status logstream-server

# Edit service settings (opens NSSM GUI)
nssm edit logstream-server

# Uninstall the service entirely
nssm remove logstream-server confirm
```

Alternatively using the built-in `sc` command:

```powershell
Start-Service logstream-server
Stop-Service  logstream-server
```

---

## 13. Open Windows Firewall ports

Allow inbound traffic on the HTTP and WebSocket ports (or just 443 if using a reverse proxy):

```powershell
# Allow HTTP API port
New-NetFirewallRule -DisplayName "LogStream HTTP API" `
    -Direction Inbound -Protocol TCP -LocalPort 8081 -Action Allow

# Allow WebSocket port
New-NetFirewallRule -DisplayName "LogStream WebSocket" `
    -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow
```

---

## 14. (Optional) Nginx reverse proxy with HTTPS on Windows

For production use you should put Nginx in front to handle HTTPS.

### Install Nginx for Windows

Download the stable release from [https://nginx.org/en/download.html](https://nginx.org/en/download.html) and extract to `C:\nginx`.

### Install as a service with NSSM

```powershell
nssm install nginx "C:\nginx\nginx.exe"
nssm set nginx AppDirectory "C:\nginx"
nssm start nginx
```

### Obtain an SSL certificate with win-acme

win-acme is the Windows equivalent of Certbot:

```powershell
# Download win-acme from https://www.win-acme.com
# Extract to C:\win-acme and run:
C:\win-acme\wacs.exe
```

Follow the interactive prompts to issue a certificate for your domain.

### Configure Nginx

Edit `C:\nginx\conf\nginx.conf`:

```nginx
worker_processes 1;

events {
    worker_connections 1024;
}

http {
    server {
        listen 80;
        server_name logs.yourdomain.com;
        return 301 https://$host$request_uri;
    }

    server {
        listen 443 ssl;
        server_name logs.yourdomain.com;

        ssl_certificate     C:/win-acme/certs/logs.yourdomain.com/fullchain.pem;
        ssl_certificate_key C:/win-acme/certs/logs.yourdomain.com/privkey.pem;

        location /api/ {
            proxy_pass         http://127.0.0.1:8081;
            proxy_http_version 1.1;
            proxy_set_header Host              $host;
            proxy_set_header X-Real-IP         $remote_addr;
            proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
        }

        location /ws {
            proxy_pass         http://127.0.0.1:8080;
            proxy_http_version 1.1;
            proxy_set_header Upgrade    $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_read_timeout  3600s;
            proxy_send_timeout  3600s;
        }
    }
}
```

Reload Nginx after any config change:

```powershell
C:\nginx\nginx.exe -s reload
```

Block the raw ports from outside once Nginx is in front:

```powershell
# Remove the rules we added in step 13
Remove-NetFirewallRule -DisplayName "LogStream HTTP API"
Remove-NetFirewallRule -DisplayName "LogStream WebSocket"

# Allow only HTTP and HTTPS through the firewall
New-NetFirewallRule -DisplayName "HTTP"  -Direction Inbound -Protocol TCP -LocalPort 80  -Action Allow
New-NetFirewallRule -DisplayName "HTTPS" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow
```

---

## 15. Final end-to-end verification

```powershell
# Health check
Invoke-RestMethod http://localhost:8081/api/health

# Send a test log entry
$body = @{
    app_key  = "deploy-test"
    app_id   = "windows"
    level    = "info"
    category = "deployment"
    message  = "Server deployed successfully on Windows"
} | ConvertTo-Json

Invoke-RestMethod `
    -Uri "http://localhost:8081/api/logs" `
    -Method POST `
    -Headers @{
        "Authorization" = "Bearer <API_SECRET>"
        "Content-Type"  = "application/json"
        "User-Agent"    = "PowerShellTest/1.0"
    } `
    -Body $body

# Read it back
Invoke-RestMethod `
    -Uri "http://localhost:8081/api/logs?app_key=deploy-test" `
    -Headers @{ "Authorization" = "Bearer <UI_SECRET>" }
```

---

## Day-to-day operations

### View service logs

```powershell
# Tail the stdout log
Get-Content "C:\logstream-server\storage\service-stdout.log" -Wait -Tail 50
```

### Deploy a new version manually

```powershell
cd C:\logstream-server

git pull origin main

composer install --no-dev --optimize-autoloader --no-interaction

nssm restart logstream-server

# Confirm healthy
Invoke-RestMethod http://localhost:8081/api/health
```

### Run database migrations (MariaDB only)

```powershell
mysql -h 127.0.0.1 -u logservice -p logservice `
    < C:\logstream-server\migrations\001_logs.sql
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `php` not found in PowerShell | PHP not in PATH | Add `C:\php8.3` to system PATH and reopen PowerShell |
| `The specified module could not be found` PHP error | DLL dependency missing | Install [Visual C++ Redistributable](https://aka.ms/vs/17/release/vc_redist.x64.exe) |
| `sockets` extension error | Extension not enabled | Uncomment `extension=sockets` in `C:\php8.3\php.ini` |
| Service starts then immediately stops | PHP error on startup | Check `C:\logstream-server\storage\service-stderr.log` |
| Port already in use | Another process on 8080/8081 | Run `netstat -ano | findstr :8081` to find the PID |
| `composer: command not found` | Composer installer did not update PATH | Reopen PowerShell or re-run the Composer installer |
| `401 Unauthorized` on `POST /api/logs` | Wrong or missing `API_SECRET` | Check `Authorization: Bearer <API_SECRET>` header |
| `401 Unauthorized` on `GET /api/logs` | Wrong or missing `UI_SECRET` | Check `Authorization: Bearer <UI_SECRET>` header |
| WebSocket connection rejected | Wrong or missing token | Connect with `wss://domain/ws?token=<UI_SECRET>` |

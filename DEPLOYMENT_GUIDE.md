# Social Media Automation — Deployment & Setup Guide
## Deployment to Sectona Cloud Server

## 1. Overview

This is a multi-platform social media inbox & AI auto-reply system that supports:

| Platform | Real-time Webhook | Sync Method | OAuth Required |
|----------|------------------|-------------|----------------|
| Facebook | ✅ Webhook (fan page) | Poll fallback | Yes (Facebook App) |
| Instagram | ✅ Via Facebook webhook | — | Yes (same as Facebook) |
| YouTube | ✅ PubSubHubbub | Cron fallback | Yes (Google OAuth) |
| Twitter / X | ❌ No webhook available | Cron only | Yes (X Developer) |
| LinkedIn | ✅ Webhook (with approval) | Cron fallback | Yes (LinkedIn Developer) |

---

## 2. AI Provider Configuration (Ollama)

### 2.1 Install Ollama on Cloud Server

```bash
# Ubuntu/Debian
curl -fsSL https://ollama.ai/install.sh | sh

# Pull your preferred model
ollama pull llama3.1:8b
ollama pull mistral:7b
ollama pull gemma2:9b
```

### 2.2 Configure in Laravel `.env`

```env
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1:8b
OLLAMA_TIMEOUT=120
```

### 2.3 Environment Variables Required

| Variable | Description | Example |
|----------|-------------|---------|
| `OLLAMA_BASE_URL` | Ollama server URL | `http://127.0.0.1:11434` |
| `OLLAMA_MODEL` | Model name to use | `llama3.1:8b` |
| `OLLAMA_TIMEOUT` | Request timeout in seconds | `120` |

---

## 3. Cloud Server Requirements (Sectona Server)

### 3.1 System Requirements

- **OS**: Ubuntu 22.04 LTS / Debian 12
- **PHP**: 8.2+
- **Composer**: 2.x
- **MySQL**: 8.0+ or MariaDB 10.6+
- **Nginx** or **Apache**
- **Redis** (for queue & cache) — recommended
- **Supervisor** (for queue workers)
- **Node.js 18+** (if using frontend build)
- **SSL Certificate** (Let's Encrypt recommended)

### 3.2 Ollama Requirements

- **RAM**: 16GB minimum (8GB if using 7B model only)
- **Disk**: 20GB+ for model storage
- **CPU**: 4+ cores recommended
- **Port**: 11434 (must be accessible locally, do NOT expose to internet)

### 3.3 Server Setup Steps

```bash
# 1. Update system
sudo apt update && sudo apt upgrade -y

# 2. Install PHP 8.2 + extensions
sudo apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-xml php8.2-mbstring php8.2-curl php8.2-gd php8.2-zip php8.2-bcmath php8.2-intl php8.2-redis

# 3. Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 4. Install Redis
sudo apt install -y redis-server
sudo systemctl enable redis-server

# 5. Install Nginx
sudo apt install -y nginx
sudo systemctl enable nginx

# 6. Install Supervisor
sudo apt install -y supervisor

# 7. Install MySQL
sudo apt install -y mysql-server
# Secure installation
sudo mysql_secure_installation

# 8. Install Ollama
curl -fsSL https://ollama.ai/install.sh | sh
ollama pull llama3.1:8b

# 9. Install PM2 (for queue workers)
sudo npm install -g pm2
```

### 3.4 Deploy Application

```bash
# Clone repository
cd /var/www
sudo git clone <your-repo-url> social-media-automation
cd social-media-automation

# Install dependencies
composer install --no-dev --optimize-autoloader

# Setup environment
cp .env.example .env
nano .env # Configure all environment variables

# Generate key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Setup storage
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache

# Cache config
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 3.5 Queue Worker Setup (Supervisor)

Create `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/social-media-automation/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
directory=/var/www/social-media-automation
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/social-media-automation/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### 3.6 Scheduled Task (Cron)

```bash
# Edit crontab
sudo crontab -e

# Add:
* * * * * cd /var/www/social-media-automation && php artisan schedule:run >> /dev/null 2>&1
```

---

## 4. Nginx Configuration

```nginx
server {
 listen 80;
 server_name your-domain.com;
 return 301 https://$server_name$request_uri;
}

server {
 listen 443 ssl http2;
 server_name your-domain.com;

 ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
 ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

 root /var/www/social-media-automation/public;
 index index.php;

 add_header X-Frame-Options "SAMEORIGIN";
 add_header X-Content-Type-Options "nosniff";

 location / {
 try_files $uri $uri/ /index.php?$query_string;
 }

 location = /favicon.ico { access_log off; log_not_found off; }
 location = /robots.txt { access_log off; log_not_found off; }

 location ~ \.php$ {
 fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
 fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
 include fastcgi_params;
 fastcgi_hide_header X-Powered-By;
 }

 location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
 expires 1y;
 add_header Cache-Control "public, immutable";
 access_log off;
 }

 # Webhook endpoints (no auth, accessible from outside)
 location ~ ^/webhooks/(meta|youtube|linkedin)$ {
 try_files $uri $uri/ /index.php?$query_string;
 # Optional: IP whitelist for Meta webhooks
 # allow 31.13.0.0/16;
 # allow 66.220.0.0/16;
 # allow 69.63.176.0/20;
 # allow 69.171.224.0/19;
 # deny all;
 }
}
```

---

## 5. Platform-Specific Setup

### 5.1 Facebook / Instagram Setup

1. **Create Facebook App** at [developers.facebook.com](https://developers.facebook.com)
2. Add **Webhooks** product
3. Set webhook callback URL: `https://your-domain.com/webhooks/meta`
4. Verify token: any random string (store in `.env` as `FACEBOOK_VERIFY_TOKEN`)
5. Subscribe to fields: `feed`, `comments`
6. For Instagram: Connect Instagram Business/Creator account to your Facebook Page
7. **Environment Variables**:

```env
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
FACEBOOK_VERIFY_TOKEN=your_verify_token
FACEBOOK_GRAPH_VERSION=v19.0
```

### 5.2 YouTube Setup

1. **Create Google Cloud Project** at [console.cloud.google.com](https://console.cloud.google.com)
2. Enable **YouTube Data API v3**
3. Create OAuth 2.0 credentials (Web application)
4. Add authorized redirect URI: `https://your-domain.com/auth/youtube/callback`
5. **Environment Variables**:

```env
YOUTUBE_CLIENT_ID=your_client_id
YOUTUBE_CLIENT_SECRET=your_client_secret
YOUTUBE_REDIRECT_URI=https://your-domain.com/auth/youtube/callback
```

6. After connecting a channel, use the **Subscribe** button in the UI to enable real-time webhooks via PubSubHubbub

### 5.3 Twitter / X Setup

1. **Create Project** at [developer.x.com](https://developer.x.com)
2. Enable OAuth 2.0 with PKCE
3. Required scopes: `tweet.read`, `tweet.write`, `users.read`, `offline.access`
4. Add redirect URI: `https://your-domain.com/auth/twitter/callback`
5. **Environment Variables**:

```env
TWITTER_CLIENT_ID=your_client_id
TWITTER_CLIENT_SECRET=your_client_secret
TWITTER_REDIRECT_URI=https://your-domain.com/auth/twitter/callback
TWITTER_API_BASE=https://api.x.com/2
```

**Note**: Twitter does NOT support webhooks for comments. The system uses cron-based polling only.

### 5.4 LinkedIn Setup

1. **Create App** at [linkedin.com/developers](https://www.linkedin.com/developers/)
2. Request access to **Community Management API** and **Marketing Developer Platform**
3. Add redirect URI: `https://your-domain.com/auth/linkedin/callback`
4. For webhooks: In your app settings, add webhook URL `https://your-domain.com/webhooks/linkedin`
5. Subscribe to events: `COMMENT` events on your organization UGC posts
6. **Environment Variables**:

```env
LINKEDIN_CLIENT_ID=your_client_id
LINKEDIN_CLIENT_SECRET=your_client_secret
LINKEDIN_REDIRECT_URI=https://your-domain.com/auth/linkedin/callback
LINKEDIN_API_BASE=https://api.linkedin.com
LINKEDIN_WEBHOOK_SECRET=your_webhook_secret
```

**Note**: LinkedIn webhooks require LinkedIn product approval. Use cron sync as fallback.

---

## 6. Complete `.env` Template

```env
APP_NAME="Social Media Automation"
APP_ENV=production
APP_KEY=base64:YOUR_APP_KEY
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=social_media_automation
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Queue
QUEUE_CONNECTION=database
BROADCAST_DRIVER=log
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# AI / Ollama
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1:8b
OLLAMA_TIMEOUT=120

# Facebook / Instagram
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
FACEBOOK_VERIFY_TOKEN=
FACEBOOK_GRAPH_VERSION=v19.0

# YouTube
YOUTUBE_CLIENT_ID=
YOUTUBE_CLIENT_SECRET=
YOUTUBE_REDIRECT_URI=https://your-domain.com/auth/youtube/callback

# Twitter / X
TWITTER_CLIENT_ID=
TWITTER_CLIENT_SECRET=
TWITTER_REDIRECT_URI=https://your-domain.com/auth/twitter/callback
TWITTER_API_BASE=https://api.x.com/2

# LinkedIn
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_REDIRECT_URI=https://your-domain.com/auth/linkedin/callback
LINKEDIN_API_BASE=https://api.linkedin.com
LINKEDIN_WEBHOOK_SECRET=
```

---

## 7. Cron Schedule Configuration

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
 // Sync all active accounts every 5 minutes
 // Uses since_id / last_synced_at for incremental sync
 $schedule->job(new \App\Jobs\SyncSocialCommentsJob())
 ->everyFiveMinutes()
 ->withoutOverlapping();

 // Clean up soft-deleted comments older than 90 days (optional)
 $schedule->call(function () {
 \App\Models\SocialComment::onlyTrashed()
 ->where('deleted_at', '<', now()->subDays(90))
 ->forceDelete();
 })->daily();
}
```

**Current platforms handled by `SyncSocialCommentsJob`**: YouTube, Twitter, LinkedIn
**Facebook/Instagram**: Handled via webhook + manual sync trigger

---

## 8. Testing Checklist

### 8.1 Webhook Testing

```bash
# Facebook Webhook
curl -X POST https://your-domain.com/webhooks/meta \
 -H "Content-Type: application/json" \
 -d '{"object":"page","entry":[{"id":"PAGE_ID","changes":[{"field":"feed","value":{"verb":"add","item":"comment","comment_id":"TEST_ID","message":"test","from":{"id":"USER_ID","name":"Test User"}}}]}]}'

# YouTube Webhook (GET - verification)
curl "https://your-domain.com/webhooks/youtube?hub.mode=subscribe&hub.challenge=test123&hub.verify_token=&hub.lease_seconds=864000"

# LinkedIn Webhook (POST - verification)
curl -X POST https://your-domain.com/webhooks/linkedin \
 -H "Content-Type: application/json" \
 -d '{"verificationCode":"test123","status":"approved"}'
```

### 8.2 AI Response Testing

```bash
# Test Ollama directly
curl http://127.0.0.1:11434/api/generate \
 -d '{"model":"llama3.1:8b","prompt":"Hello! How are you?","stream":false}'

# Test via Laravel tinker
php artisan tinker
>>> App\Jobs\AnalyzeWithOllama::dispatch(App\Models\SocialComment::first());
```

### 8.3 Connection Testing

```bash
# Test database connection
php artisan migrate:status

# Test Redis connection
php artisan queue:work --once

# Check queue workers
sudo supervisorctl status

# Check Ollama
curl http://127.0.0.1:11434/api/tags
```

---

## 9. Security Checklist

- [ ] `.env` file is NOT in version control
- [ ] `APP_DEBUG=false` in production
- [ ] SSL/HTTPS enabled on all endpoints
- [ ] Webhook endpoints have signature verification (Meta: `X-Hub-Signature-256`)
- [ ] Ollama port is NOT exposed to internet (firewall only)
- [ ] Database credentials are strong
- [ ] Queue workers run as `www-data` (not root)
- [ ] Supervisor is running and workers are `autostart=true`
- [ ] Regular backups configured for MySQL database
- [ ] Rate limiting enabled on API routes
- [ ] CORS configured properly

---

## 10. Known Limitations & Notes

1. **Twitter/X**: No webhook support. Only cron-based polling via Twitter API v2 mentions endpoint.
2. **LinkedIn**: Webhook requires LinkedIn product approval. Cron sync is the reliable fallback.
3. **YouTube**: PubSubHubbub subscriptions expire every 10 days. The `subscribeToVideoNotifications` method handles renewal on each manual sync.
4. **Facebook/Instagram**: Webhook handles real-time events. Fallback cron sync available via manual trigger.
5. **Ollama**: Must be running on the same server (or accessible via private network). Do not expose to public internet.
6. **Rate Limits**: Each platform has API rate limits. The incremental sync strategy minimizes API calls.

---

## 11. Troubleshooting

| Issue | Solution |
|-------|----------|
| Ollama timeout | Increase `OLLAMA_TIMEOUT` or use smaller model |
| Queue not processing | Check `supervisorctl status`, restart workers |
| Webhook not receiving events | Check firewall, SSL cert, domain reachability |
| Facebook webhook verification fails | Ensure HTTPS, correct verify token, reachable URL |
| YouTube subscription fails | Ensure webhook URL is publicly reachable via HTTPS |
| LinkedIn webhook silent | Check if Community Management API is approved |
| Twitter sync returns empty | Verify `last_synced_tweet_id` cursor, check API permissions |
| Instagram not syncing | Verify Instagram is linked to Facebook Page, check permissions |

---

## 12. Monitoring

```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check queue worker logs
tail -f storage/logs/worker.log

# Check Ollama logs
journalctl -u ollama -f

# Check Nginx logs
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# Check supervisor status
sudo supervisorctl status
```

---

*Generated for deployment on Sectona cloud server*

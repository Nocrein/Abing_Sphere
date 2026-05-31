# ArtSphere — Setup Guide

## Requirements
- PHP 8.0+ with PDO SQLite extension
- Apache (mod_rewrite enabled) or Nginx
- Writable `database/` and `uploads/` directories

---

## Quick Deploy (cPanel / Shared Hosting)

1. **Upload** all files to your `public_html` folder (or a subdirectory)
2. **Set permissions:**
   ```
   chmod 775 database/
   chmod 775 uploads/
   chmod 775 uploads/artworks/
   ```
3. **Configure email** — open `api/config.php` and set your Gmail App Password:
   ```php
   define('SMTP_PASS', 'your_16_char_app_password');
   ```
   > To get a Gmail App Password:
   > 1. Go to myaccount.google.com → Security
   > 2. Enable 2-Step Verification
   > 3. Search "App passwords" → Create one for "Mail"
   > 4. Paste the 16-character password above

4. **Visit** `yoursite.com` — the database is created automatically!

---

## Local Development (XAMPP / WAMP / Laragon)

1. Copy project to `htdocs/artsphere` (XAMPP) or `www/artsphere` (WAMP)
2. Enable mod_rewrite in Apache config
3. Visit `http://localhost/artsphere`

---

## Admin Access

- **URL:** `yoursite.com/admin/login.html`
- **Email:** `jeramayabing@gmail.com`
- **Password:** `admin`

> ⚠️ Change your password after first login via database or add a "change password" feature.

---

## File Structure

```
artsphere/
├── index.html              ← Public homepage (SPA)
├── .htaccess               ← Apache routing rules
├── api/
│   ├── config.php          ← Database, JWT, email config ⚙️
│   ├── auth.php            ← Login endpoint
│   ├── artworks.php        ← CRUD for artworks
│   ├── messages.php        ← Contact form + email sending
│   └── stats.php           ← Dashboard stats
├── admin/
│   ├── login.html          ← Admin login
│   └── dashboard.html      ← Admin panel (artworks + messages)
├── assets/
│   ├── css/main.css        ← Public styles
│   ├── css/admin.css       ← Admin styles
│   ├── js/main.js          ← Public JS
│   └── js/admin.js         ← Admin JS
├── uploads/
│   └── artworks/           ← Uploaded artwork images (auto-created)
└── database/
    └── artsphere.db        ← SQLite database (auto-created)
```

---

## Email Configuration Notes

The contact form sends emails to `jeramayabing@gmail.com`.

- If `SMTP_PASS` is set → uses Gmail SMTP (SSL port 465) for reliable delivery
- If not set → falls back to PHP's `mail()` function (works on most shared hosts)

For best results, always set the Gmail App Password.

---

## Security Notes

- Change `JWT_SECRET` in `config.php` to a random string
- The `database/` folder is outside public reach via `.htaccess`
- Consider adding rate limiting on the contact form for production

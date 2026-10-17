# UltimateClans Exchange

**Standalone web converter for migrating UltimateClans V8 configurations and supported data to V9.**

UltimateClans Exchange is a self-contained PHP application. It can be deployed inside an `/exchange/` directory on an existing website, without installing the rest of the original TribeCraft website or connecting to any external service.

**No framework · No database server · No external login · No fixed domain**

> [!IMPORTANT]
> This tool assists with migration; it does **not** guarantee that every legacy setting or data file can be converted automatically. Back up your V8 installation, review the generated report, and test the converted files on a non-production server before using them in production.

## Features

- **V8 → V9 configuration migration** based on bundled reference templates.
- **Preserves compatible custom settings** while retaining appropriate V9 defaults.
- **Conservative compatibility mode** for V8 versions whose structure differs significantly from the reference.
- **Optional data migration** for supported SQLite databases and YAML storage files.
- **Optional material migration** from legacy Bukkit material names to modern equivalents.
- **Addon-aware conversion:** includes compatible configuration for detected V8 addons rather than blindly enabling all addons.
- **Migration report** with warnings, skipped items, conversions, and validation results.
- **Downloadable ZIP output** with temporary, expiring download links.
- **Standalone interface** with local CSS and JavaScript assets, without a CDN.

## Requirements

| Component | Requirement |
| --- | --- |
| PHP | **8.1+** |
| PHP `zip` / `ZipArchive` | **Required** |
| PHP `pdo_sqlite` | Required to migrate SQLite data |
| PHP sessions, JSON, fileinfo | Required (typically available in standard PHP installations) |
| Web server | Apache + PHP or Nginx + PHP-FPM |
| Filesystem | PHP must be able to write to `_private/runtime/` |
| MySQL, Node.js, Composer | **Not required** |

Suggested PHP limits for uploads up to the application's default **100 MB**:

```ini
upload_max_filesize = 110M
post_max_size = 120M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
```

For Nginx, also configure `client_max_body_size 110m;` in the appropriate `server` block. Adjust PHP-FPM/web-server timeouts if necessary.

## Installation

### 1. Upload the application

Copy the **contents of this repository** into the `exchange/` directory under your web server's document root:

```text
/var/www/html/
└── exchange/
    ├── index.php
    ├── assets/
    ├── .htaccess
    ├── README.md
    └── _private/
```

The application will be available at:

```text
https://your-domain.com/exchange/
```

It uses relative paths, so **no domain configuration is required in the PHP source**. You can deploy the same folder on another host or domain.

### 2. Make the runtime directory writable

On an Ubuntu server where the PHP process runs as `www-data`:

```bash
sudo chown -R www-data:www-data /var/www/html/exchange/_private/runtime
sudo find /var/www/html/exchange/_private/runtime -type d -exec chmod 700 {} \;
sudo find /var/www/html/exchange/_private/runtime -type f -exec chmod 600 {} \;
```

Replace the path and service user to match your hosting environment. On shared hosting, configure folder permissions using your hosting control panel.

### 3. Deny public access to `_private/`

This step is **mandatory**. `_private/` contains the converter, configuration templates, uploaded content, generated results, and reports.

#### Apache

The repository provides `_private/.htaccess`:

```apache
Require all denied
Options -Indexes
```

Make sure Apache is configured to honor `.htaccess` rules (`AllowOverride` enabled). If your server ignores `.htaccess`, add an equivalent access restriction to the Apache virtual host configuration.

#### Nginx

Nginx **does not read** `.htaccess`. Add the following rule to the existing `server { ... }` block:

```nginx
location ^~ /exchange/_private/ {
    return 404;
}
```

Make sure your server already has a working PHP-FPM handler for `/exchange/index.php`. If not, an example is provided in `_private/nginx-example.conf`; update the PHP-FPM socket and document root to match your server.

After changing the Nginx configuration:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

> [!CAUTION]
> **Do not put the converter online until private-file requests are blocked.** Verify that both `/exchange/_private/config.php` and `/exchange/_private/templates/v8/config.yml` return HTTP **403 or 404**, never 200.

### 4. Verify the installation

Open:

```text
https://your-domain.com/exchange/
```

Confirm that the page loads, the stylesheet and script are present, and the PHP extension indicators show the expected support.

## How to use

1. **Back up** your existing UltimateClans V8 plugin folder.
2. Create a ZIP containing either the complete V8 plugin folder or its files directly at the ZIP root.
3. Open `/exchange/` and upload the ZIP.
4. Choose whether to **migrate storage data** (SQLite/YAML) and **convert legacy material names**.
5. Run the conversion and download both the **V9 ZIP** and the **migration report**.
6. Review the warnings and test the result in a separate Minecraft server before replacing your live files.

If your hosting environment has no `pdo_sqlite` extension, disable storage migration and convert configuration files only; SQLite storage cannot be migrated there.

### What is not guaranteed

- Automatic migration of every V8 addon or third-party integration.
- Conversion of unknown or unsupported data formats.
- Perfect translation of custom language YAML files into newer V9 schemas.
- Preservation of legacy settings that conflict with the V9 structure.

**Always read the migration report.**

## Project structure

```text
exchange/
├── index.php                      # Standalone web interface and download endpoints
├── assets/
│   ├── style.css                  # Local styles
│   └── app.js                     # Local UI behavior
├── .htaccess                      # Apache directory settings
├── README.md
└── _private/                      # Must never be publicly accessible
    ├── .htaccess                  # Apache access restriction
    ├── config.php                 # Limits and relative filesystem paths
    ├── lib/                       # Migration engine and validation rules
    ├── templates/                 # V8 and V9 config/storage templates
    ├── runtime/
    │   ├── uploads/               # Temporary uploads
    │   ├── work/                  # In-progress conversions
    │   └── results/               # Output ZIPs and reports
    ├── bin/
    │   ├── cleanup.php            # Command-line cleanup
    │   └── router.php             # Development-only PHP router
    ├── tests/
    │   └── regression.php         # Regression test suite
    └── nginx-example.conf         # Example Nginx configuration
```

## Configuration

Settings are stored in **`_private/config.php`**. The default configuration includes:

| Setting | Default | Purpose |
| --- | --- | --- |
| `max_upload_bytes` | 100 MB | Maximum input ZIP size |
| `max_extracted_files` | 2,000 | Maximum extracted file count |
| `max_extracted_bytes` | 300 MB | Maximum total uncompressed size |
| `result_ttl` | 3,600 seconds | Result and report lifetime (60 minutes) |
| `rate_limit_client_max` | 3 | Conversion attempts per client window |
| `rate_limit_client_window` | 600 seconds | Per-client window (10 minutes) |
| `rate_limit_global_max` | 30 | Conversion attempts per global window |
| `rate_limit_global_window` | 3,600 seconds | Global window (1 hour) |

The application runs **one conversion at a time** to limit resource usage. Paths in `config.php` are relative to the application directory and are not tied to a specific domain.

## Cleanup and privacy

Uploaded data, converted ZIPs, and migration reports may contain sensitive server information. Handle them accordingly:

- Use **HTTPS** on public deployments.
- Keep `_private/` inaccessible over HTTP.
- Do not commit runtime uploads, results, or generated temporary files to Git.
- Result links act as **bearer tokens**: anyone with the link can download the file while it exists. Avoid sharing them.
- Results normally expire after **60 minutes**. Cleanup runs during application access, but scheduled cleanup is recommended so unused files also expire when there are no visitors.

Optional cleanup via cron (adjust the path):

```cron
*/10 * * * * /usr/bin/php /var/www/html/exchange/_private/bin/cleanup.php >/dev/null 2>&1
```

You can also run cleanup manually:

```bash
php /var/www/html/exchange/_private/bin/cleanup.php
```

Rate limiting uses the client address provided by the web server. When running behind a reverse proxy or CDN, configure trusted client IP forwarding **in the web server** rather than trusting arbitrary request headers in the application.

## Development and testing

Run the automated PHP regression suite:

```bash
php _private/tests/regression.php
```

To test locally, go to the **parent directory** of the `exchange/` folder and start the PHP development server:

```bash
php -S 127.0.0.1:8080 -t . exchange/_private/bin/router.php
```

Then open `http://127.0.0.1:8080/exchange/`.

The development router limits accessible paths, but **the PHP built-in server must not be used for production hosting**.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Blank page or HTTP 500 | PHP version, PHP-FPM/Apache error logs, file permissions |
| ZIP upload rejected | `upload_max_filesize`, `post_max_size`, and Nginx `client_max_body_size` |
| `ZipArchive` missing | Install/enable the PHP ZIP extension |
| SQLite migration unavailable | Install/enable the PHP SQLite PDO extension |
| Conversion fails when saving output | Write access to `_private/runtime/` and available disk space |
| Report/download is unavailable | Token expiration, cleanup, or deleted runtime files |
| Private files are publicly accessible | Fix Apache `AllowOverride` or add the required Nginx deny rule **before deployment** |

## License

No license is declared by this README. If you intend to make this repository publicly reusable, add a separate `LICENSE` file with the terms you choose.

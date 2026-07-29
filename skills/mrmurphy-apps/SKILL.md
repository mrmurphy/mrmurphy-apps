---
name: mrmurphy-apps
description: >-
  Manage static apps hosted on WordPress via the MrMurphy Apps REST API.
  Create, upload, publish, and delete apps; manage environment variables;
  and build server-side PHP routes. Use when the user wants to deploy a
  static app to WordPress, manage apps programmatically, or build server-side
  routes for an app hosted under /apps/{slug}/.
---

# MrMurphy Apps REST API

Manage static apps hosted on WordPress. The plugin exposes two API namespaces:

- **Management API** (`/wp-json/mrmurphy-apps/v1/`) — Create, upload, publish, and delete apps. Requires Application Password auth.
- **App Data API** (`/wp-json/apps/v1/{slug}/`) — Per-app data endpoints. Uses cookie auth + scoped nonce.

## Prerequisites

The target WordPress site must have the [MrMurphy Apps](https://github.com/mrmurphy/mrmurphy-apps) plugin installed and activated.

An "Agent" user with the `mrmurphy_agent` role (or an admin user) must exist with an application password.

### WordPress.com hosting

On WordPress.com, the web server returns 404 for requests with static file extensions (`.js`, `.css`, `.png`, etc.) before they reach PHP. This breaks app assets like `sw.js`, images, and stylesheets served under `/apps/{slug}/`.

To fix: visit `https://wordpress.com/sites/<site>/settings/static-file-404` and enable "Let PHP handle 404s for static files." This allows the plugin's rewrite rules to intercept `/apps/{slug}/*` requests and serve assets from the uploads directory.

On self-hosted WordPress (Apache, nginx), this is not required — standard `try_files` or mod_rewrite handles it automatically.

## Authentication

Management API uses WordPress Application Passwords:

```bash
curl -u "username:application-password" https://example.com/wp-json/mrmurphy-apps/v1/apps
```

App Data API uses cookie authentication with an X-WP-Nonce injected into served HTML pages.

## Environment Variables (Evars)

Store credentials and config in the evar API instead of pasting them into prompts. Evars stay out of conversation history.

### Set global evars

```bash
curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/global-evars \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"name":"MRMURPHY_APPS_USERNAME","value":"agent"}'

curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/global-evars \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"name":"MRMURPHY_APPS_PASSWORD","value":"xxxx xxxx xxxx xxxx"}'

curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/global-evars \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"name":"MRMURPHY_APPS_URL","value":"https://example.com"}'
```

### List and manage evars

```bash
# List global evar names (values masked)
curl -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/global-evars

# Get full value of a global evar
curl -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/global-evars/MRMURPHY_APPS_URL

# List app-scoped evars
curl -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}/evars

# Set an app-scoped evar
curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}/evars \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"name":"API_KEY","value":"secret123"}'

# Delete an evar
curl -X DELETE -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/global-evars/MRMURPHY_APPS_PASSWORD
```

Variable names must match `^[A-Z_][A-Z0-9_]{1,63}$`. Values are returned masked in list responses, unmasked in individual get responses.

## Management API Endpoints

### Create an app

```bash
curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/apps \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "My App",
    "slug": "my-app",
    "zip_base64": "<base64-encoded zip>",
    "entry_file": "index.html"
  }'
```

All fields are optional. If `slug` is omitted, it's derived from `title`. If `zip_base64` is omitted, the app is created empty.

Response: `{ "id", "slug", "title", "status", "public_url", "entry_file", "file_count" }`

### List all apps

```bash
curl -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/apps
```

Returns an array of app objects.

### Get app details

```bash
curl -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}
```

Returns full details including file list and stats.

### Upload a zip to an existing app

```bash
curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}/upload \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"zip_base64": "...", "entry_file": "index.html"}'
```

Replaces all existing files for the app.

### Publish or unpublish an app

```bash
curl -X POST https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}/publish \
  -u "agent:password" \
  -H "Content-Type: application/json" \
  -d '{"status": "publish"}'
```

`status` can be `"publish"` or `"draft"`.

### Delete an app

```bash
curl -X DELETE -u "agent:password" https://example.com/wp-json/mrmurphy-apps/v1/apps/{slug}
```

Permanently deletes the app post and all stored files.

## Server-Side Code

Apps can ship PHP files that run on the server inside the zip:

### `server/init.php`

Runs once when a zip is uploaded. Use for database table creation, option setup, or one-time initialization.

Variable in scope: `$mrmurphy_app_slug` (string)

```php
<?php
global $wpdb;
$table = $wpdb->prefix . 'app_' . str_replace( '-', '_', $mrmurphy_app_slug );
$wpdb->query( "CREATE TABLE IF NOT EXISTS $table (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    content text NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id)
)" );
```

### `server/routes.php`

Loaded on every REST API request for published apps. Register custom endpoints under `apps/v1/{slug}/`.

```php
<?php
$slug = $mrmurphy_app_slug;
register_rest_route( "apps/v1/$slug", '/notes', array(
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => function () use ( $slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'app_' . str_replace( '-', '_', $slug );
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
    },
    'permission_callback' => 'is_user_logged_in',
) );
```

The server code has full access to WordPress APIs: `$wpdb`, `wp_get_current_user()`, `get_current_user_id()`, `register_rest_route()`, etc.

Routes are automatically namespaced under `apps/v1/{slug}/` — no collisions with other apps.

### Accessing Environment Variables in Server-Side Code

Evars set via the Management API can be read from server-side code. App-scoped evars are stored as post meta; global evars as WordPress options.

```php
function mrmurphy_evar( $name, $default = '' ) {
    global $wpdb;
    $post = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'mrmurphy_app'",
            $mrmurphy_app_slug
        )
    );
    if ( $post ) {
        $data = get_post_meta( $post->ID, '_mrmurphy_app_evar_' . $name, true );
        if ( is_array( $data ) && isset( $data['value'] ) ) {
            return $data['value'];
        }
    }
    $data = get_option( '_mrmurphy_global_evar_' . $name );
    if ( is_array( $data ) && isset( $data['value'] ) ) {
        return $data['value'];
    }
    return $default;
}

// Usage:
$api_key = mrmurphy_evar( 'API_KEY', 'default-key' );
```

## App Data API

Apps can expose data endpoints under `apps/v1/{slug}/`. These use cookie auth + the nonce injected by the plugin.

The plugin injects `window.mrmurphyApps` into served HTML pages with:

- `slug` — the app's slug
- `root` — REST API root URL
- `apiBase` — base URL for this app's scoped endpoints (`apps/v1/{slug}`)
- `nonce` — scoped nonce for cookie auth on `apps/v1/{slug}/*` only
- `user` — `{ id, display_name }` (null if not logged in)

Example: a counter app at `/apps/counter/` uses `apiBase + "/counter"` to hit `POST apps/v1/counter/counter`.

## Static Asset Hosting

Public URL pattern: `https://example.com/apps/{slug}/`

The plugin injects a `<base>` tag pointing to `/apps/{slug}/`. All relative asset paths (`src="bundle.js"`, `href="style.css"`) resolve through the plugin's rewrite rule.

Use absolute paths (leading `/`) or `window.mrmurphyApps.apiBase` for form actions, navigation links, and History API calls. Hash-based routing (`#/route`) is recommended for SPAs and is unaffected by the base tag.

## Error Responses

| Status | Meaning |
|--------|---------|
| 400 | Bad request (missing/invalid parameters) |
| 401 | Unauthorized (not logged in) |
| 403 | Forbidden (insufficient permissions) |
| 404 | App not found |
| 409 | Conflict (e.g., slug already exists) |
| 500 | Server error |

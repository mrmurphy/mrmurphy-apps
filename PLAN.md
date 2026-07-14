# REST Upload Endpoint for mrmurphy-apps

## Problem

Currently the only way to upload an app is through the wp-admin UI (file input on the post edit screen). The agent needs a programmatic way to upload new versions without human interaction.

## Approach: Base64-encoded zip via REST

WordPress REST doesn't support `multipart/form-data`. The simplest approach for an agent is to send the zip as base64 in a JSON body. The existing `class-storage.php` import logic is already solid (path traversal protection, extension blocking, symlink detection, size limits) and should be reused directly.

## New File: `inc/class-rest.php`

Single class that registers all REST routes under `mrmurphy-apps/v1`.

## Endpoints

| Method | Route | Description |
|---|---|---|
| `GET` | `/mrmurphy-apps/v1/instructions` | TXT guide for agents on how to use this API |
| `POST` | `/mrmurphy-apps/v1/apps` | Create a new app (optional: upload zip at creation) |
| `GET` | `/mrmurphy-apps/v1/apps` | List all apps |
| `GET` | `/mrmurphy-apps/v1/apps/{slug}` | Get app details (files, entry, stats) |
| `POST` | `/mrmurphy-apps/v1/apps/{slug}/upload` | Upload/replace zip for an existing app |
| `POST` | `/mrmurphy-apps/v1/apps/{slug}/publish` | Toggle publish/draft status |
| `DELETE` | `/mrmurphy-apps/v1/apps/{slug}` | Delete an app and its files |

### `GET /mrmurphy-apps/v1/instructions`

Returns a plain-text (`Content-Type: text/plain`) document that teaches an agent everything it needs to know to use this API: authentication, endpoint descriptions, request/response examples, error codes, and the app build process. No authentication required — it's public reference material. The body covers:

- How to authenticate with WordPress Application Passwords
- How to create an app (with or without an initial zip)
- How to upload a new version (base64-encoded zip)
- How to publish, list, and inspect apps
- The build process (`build.sh`) and expected zip structure
- Error responses and what they mean
- The public URL pattern (`/apps/{slug}/`)

This lets an agent discover the API by fetching the instructions first, then following the steps.

### `POST /mrmurphy-apps/v1/apps`

Create a new app post. Optionally include a zip at creation time.

**Request:**
```
POST /wp-json/mrmurphy-apps/v1/apps
Content-Type: application/json

{
  "title": "My App",
  "slug": "my-app",          // optional, auto-generated from title
  "zip_base64": "...",       // optional, upload zip at creation
  "entry_file": "index.html" // optional, auto-detected if zip provided
}
```

**Response:**
```json
{
  "id": 42,
  "slug": "my-app",
  "title": "My App",
  "status": "draft",
  "public_url": "https://example.com/apps/my-app/",
  "entry_file": "index.html",
  "file_count": 12
}
```

### `GET /mrmurphy-apps/v1/apps`

List all apps.

**Response:**
```json
[
  {
    "id": 42,
    "slug": "my-app",
    "title": "My App",
    "status": "publish",
    "public_url": "https://example.com/apps/my-app/",
    "entry_file": "index.html",
    "file_count": 12
  }
]
```

### `GET /mrmurphy-apps/v1/apps/{slug}`

Get details for a specific app.

**Response:**
```json
{
  "id": 42,
  "slug": "my-app",
  "title": "My App",
  "status": "publish",
  "public_url": "https://example.com/apps/my-app/",
  "entry_file": "index.html",
  "files": ["index.html", "style.css", "app.js", "images/logo.png"],
  "file_count": 12,
  "stats": {
    "total_visits": 1337,
    "unique_visitors": 42,
    "last_7_days": 15
  }
}
```

### `POST /mrmurphy-apps/v1/apps/{slug}/upload`

Upload or replace the zip for an existing app.

**Request:**
```
POST /wp-json/mrmurphy-apps/v1/apps/my-app/upload
Content-Type: application/json

{
  "zip_base64": "UEsFBB...<base64 encoded zip>...",
  "entry_file": "index.html"  // optional, auto-detected if omitted
}
```

**Response:**
```json
{
  "slug": "my-app",
  "public_url": "https://example.com/apps/my-app/",
  "entry_file": "index.html",
  "file_count": 12,
  "message": "App files uploaded successfully."
}
```

### `POST /mrmurphy-apps/v1/apps/{slug}/publish`

Toggle an app between draft and published status.

**Request:**
```
POST /wp-json/mrmurphy-apps/v1/apps/my-app/publish
Content-Type: application/json

{
  "status": "publish"  // or "draft"
}
```

### `DELETE /mrmurphy-apps/v1/apps/{slug}`

Delete an app and all its stored files.

## Auth: WordPress Application Passwords

The agent creates a dedicated user (or uses an existing one) with an application password. All endpoints (except `GET /instructions`) use `is_user_logged_in()` + capability checks (`manage_options`). This is the standard WP way, no custom auth needed.

## `upload` endpoint flow

1. Permission check (`manage_options`)
2. Look up app by slug via `MRMurphy_Apps_CPT::get_app_by_slug()`
3. Decode base64 to a temp file (clean up on exit via try/finally pattern)
4. Call `$this->storage->import_zip($post_id, $temp_file)` — reuses all existing validation
5. Update entry file meta if provided
6. Return response with file count, public URL, and detected entry file

## Memory considerations

Base64 inflates by ~33%. For a 50MB zip limit, that's ~66MB in memory for the decoded string. This is acceptable for agent use. If needed later, we can add a chunked upload endpoint, but let's start simple.

## Files to modify/create

| File | Action |
|---|---|
| `inc/class-rest.php` | **Create** — REST controller with all routes, including the instructions endpoint |
| `inc/class-plugin.php` | **Modify** — instantiate `MRMurphy_Apps_REST` in the singleton (line ~62, alongside the Admin conditional) |
| `inc/class-cpt.php` | **No change** — reuse `get_app_by_slug()` and `get_entry_file()` |
| `inc/class-storage.php` | **No change** — reuse `import_zip()`, `list_files()`, `delete_app_files()` |

## Security

- `permission_callback` on every route (except `GET /instructions`): `function() { return current_user_can('manage_options'); }`
- Base64 decode goes to `sys_get_temp_dir()`, cleaned up regardless of success/failure
- All existing storage protections apply: path traversal, blocked extensions, symlinks, size limits, compression bombs
- Slug validation via `sanitize_title()` on create, exact match on existing lookups (no arbitrary slug manipulation)
- `ABSPATH` guard on the new file

## Agent workflow

```bash
# 1. Read the API instructions
curl "https://murphy-randle.studio/wp-json/mrmurphy-apps/v1/instructions"

# 2. Build the app zip
cd /Users/murphy/projects/mrmurphy-apps && ./build.sh

# 3. Upload via REST
curl -X POST "https://murphy-randle.studio/wp-json/mrmurphy-apps/v1/apps/my-app/upload" \
  -u "agent-user:app-password" \
  -H "Content-Type: application/json" \
  -d "{\"zip_base64\": \"$(base64 -i dist/my-app.zip)\"}"

# 4. Publish
curl -X POST "https://murphy-randle.studio/wp-json/mrmurphy-apps/v1/apps/my-app/publish" \
  -u "agent-user:app-password" \
  -H "Content-Type: application/json" \
  -d '{"status": "publish"}'
```

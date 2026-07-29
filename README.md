# MrMurphy Apps

Manage static apps hosted on WordPress via the MrMurphy Apps REST API.

## Install

```bash
npx skills add mrmurphy/mrmurphy-apps --directory skills/mrmurphy-apps
```

## What it does

- Create, upload, publish, and delete apps
- Manage environment variables (global and app-scoped)
- Build server-side PHP routes for apps
- Access evars from server-side code

## Prerequisites

- [MrMurphy Apps](https://github.com/mrmurphy/mrmurphy-apps) plugin installed on the target WordPress site
- An "Agent" user with `mrmurphy_agent` role (or admin user) with Application Password

## Quick Start

```bash
# Set credentials as evars
curl -X POST {MRMURPHY_APPS_URL}/wp-json/mrmurphy-apps/v1/global-evars \
  -u "${MRMURPHY_APPS_USERNAME}:${MRMURPHY_APPS_PASSWORD}" \
  -H "Content-Type: application/json" \
  -d '{"name":"MRMURPHY_APPS_URL","value":"'"$MRMURPHY_APPS_URL"'"}'

# Create an app
curl -X POST {MRMURPHY_APPS_URL}/wp-json/mrmurphy-apps/v1/apps \
  -u "${MRMURPHY_APPS_USERNAME}:${MRMURPHY_APPS_PASSWORD}" \
  -H "Content-Type: application/json" \
  -d '{"title":"My App","slug":"my-app","zip_base64":"<base64>"}'

# Publish
curl -X POST {MRMURPHY_APPS_URL}/wp-json/mrmurphy-apps/v1/apps/my-app/publish \
  -u "${MRMURPHY_APPS_USERNAME}:${MRMURPHY_APPS_PASSWORD}" \
  -H "Content-Type: application/json" \
  -d '{"status":"publish"}'
```

## API Reference

See the skill for full API documentation including:
- Management API endpoints
- Server-side code patterns
- Environment variable access
- App Data API usage

## License

Same as the MrMurphy Apps plugin.

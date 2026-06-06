# MrMurphy Apps

WordPress plugin for hosting static apps at `/apps/{slug}/`.

## Setup

Symlink into your WordPress site:

```bash
ln -s /Users/murphy/projects/mrmurphy-apps /path/to/site/wp-content/plugins/mrmurphy-apps
```

Activate with WP-CLI:

```bash
studio wp plugin activate mrmurphy-apps
```

## Usage

1. In wp-admin, go to **Apps → Add New**
2. Set the title and slug (`foo` → `/apps/foo/`)
3. Upload a zip of static files
4. Publish and open the public URL

Uploaded files are stored in `wp-content/uploads/mrmurphy-apps/{slug}/`.

HTML responses get a `<base href="/apps/{slug}/">` tag injected when one is not already present.

Visit stats (HTML document views) appear in the app edit screen sidebar.

## Build

Create a versioned zip for wp-admin upload:

```bash
./build.sh
```

Bump the patch version and build:

```bash
./build.sh --bump
```

Output: `dist/mrmurphy-apps-{version}.zip`

## Development

This repo lives next to [mrmurphy-theme](https://github.com/mrmurphy/mrmurphy-theme) under `~/projects/`.

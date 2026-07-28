# unraid-provisioner

Unraid plugin to deploy turnkey servers from **profiles** describing which
plugins and Docker containers to install — manually or automatically on the
first boot of the array.

## Concept

A **profile** is a JSON file describing a golden image:

```json
{
  "name": "media-server-standard",
  "version": "2026.07.28",
  "plugins": [ { "name": "...", "url": "https://.../plugin.plg" } ],
  "containers": [ { "name": "plex", "image": "linuxserver/plex:latest", ... } ]
}
```

See `profiles/example-media-server.json` for a full example.

Containers are deployed through **direct Docker API/CLI calls**
(`docker run ...` built from the profile's fields) — deliberately independent
of the Community Applications template format, to stay portable and
scriptable.

## Profile storage and distribution

- **Local**: dropped under `/boot/config/plugins/unraid-provisioner/profiles/`
  (persisted on the USB flash, survives reboots).
- **Import from a URL**: a plain `curl` of a single JSON file, copied into
  `.../library/`.
- **Centralized Git repository**: `git clone`/`pull` of a repo containing
  `profiles/*.json`, to manage a shared library across multiple servers.

Both import mechanisms feed the same `library/` folder, so all profiles show
up together on the webGui page.

## Autodeploy on first boot

1. On the **Provisioner** page, pick a profile then "Set as first-boot
   profile" → copied to `autodeploy.json`.
2. On the next array start, the `event/started` hook detects the file, runs
   `deploy.php` against it, then drops a `.autodeploy-done` marker so the
   deployment isn't replayed on later reboots.
3. To reprovision (e.g. a new image based on this master), just start from a
   fresh USB flash drive with `autodeploy.json` already in place and without
   the `.autodeploy-done` marker.

## Building a golden image from an existing server

From the **Provisioner** webGui page, use "Generate a profile from this server" -- enter a name and it captures installed plugins and running containers into a new profile, listed alongside the others immediately.

The same capture is available from the CLI:

```bash
php /usr/local/emhttp/plugins/unraid-provisioner/scripts/export-profile.php media-server-standard
```

Captures installed plugins and running containers into a profile JSON.
**Review it before reuse**: plugin URLs aren't always recoverable from the
host alone (the generated file flags them `REPLACE_WITH_ACTUAL_URL_*` when
this happens), and the captured volumes/env reflect the container's current
state, not necessarily what should be in a "clean" profile meant for
redeployment.

## Repository layout (to be packaged)

```
source/unraid-provisioner/
├── unraid-provisioner.page      # webGui page (Menu > Utilities > Provisioner)
├── include/deploy-lib.php       # deployment engine (plugins + containers)
├── scripts/
│   ├── deploy.php                # CLI: apply a profile
│   └── export-profile.php        # CLI: capture current state into a profile
├── event/started                 # hook: autodeploy on array start
└── images/                       # plugin icon

unraid-provisioner.plg            # installer, to host at a stable URL
profiles/example-media-server.json
```


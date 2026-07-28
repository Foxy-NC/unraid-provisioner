# unraid-provisioner

Unraid plugin for deploying “turnkey” servers based on **profiles**
that describe the Docker plugins and containers to be installed manually or
automatically when the array is first booted.

## Concept

A **profile** is a JSON file that describes a template:

```json
{
  “name”: “media-server-standard”,
  “version”: “2026.07.28”,
  “plugins”: [ { “name”: “...”, ‘url’: “https://.../plugin.plg” } ],
  “containers”: [ { “name”: “plex”, ‘image’: “linuxserver/plex:latest”, ... } ]
}
```

See `profiles/example-media-server.json` for a complete example.

Containers are deployed via **direct calls to the Docker API/CLI**
(`docker run ...` built from the profile fields) — intentionally
independent of the Community Applications template format, to remain
portable and scriptable.

## Profile Storage and Distribution

- **Local**: stored under `/boot/config/plugins/unraid-provisioner/profiles/`
  (persistent on the USB drive, survives reboots).
- **Import from a URL**: a simple `curl` command for a single JSON file,
  copied to `.../library/`.
- **Centralized Git Repository**: `git clone`/`pull` of a repository containing
  `profiles/*.json`, to manage a library shared among multiple
  servers.

Both import mechanisms populate the same `library/` folder, so
all profiles appear together in the WebGUI.

## Auto-deployment on first boot



1. On the **Provisioner** page, select a profile, then "Set as
   first-boot profile" → copied to `autodeploy.json`.
2. The next time the array boots, the `event/started` event hook detects the
   file, runs `deploy.php` on it, and then sets a marker
   `.autodeploy-done` so that the deployment is not repeated on subsequent
   reboots.
3. To reprovision (e.g., a new image based on this master), simply
   start from a fresh USB drive with `autodeploy.json` already in place and
   without the `.autodeploy-done` marker.

## Creating a template image from an existing server

```bash
php /usr/local/emhttp/plugins/unraid-provisioner/scripts/export-profile.php media-server-standard
```

Captures the installed plugins and running containers in a
JSON profile. **Review before reusing**: plugin URLs are not
always retrievable from the host (the generated file marks them as
`REPLACE_WITH_ACTUAL_URL_*` where applicable), and the captured volumes/env
reflect the current state of the container, not necessarily what should be
in a “clean” profile for redeployment.

## Repository structure (to be packaged)

```
source/unraid-provisioner/
├── unraid-provisioner.page      # Web GUI page (Menu > Utilities > Provisioner)
├── include/deploy-lib.php       # Deployment engine (plugins + containers)
├── scripts/
│   ├── deploy.php                # CLI: applies a profile
│   └── export-profile.php        # CLI: saves the current state as a profile
├── event/started                 # hook: auto-deployment on startup array
└── images/                       # plugin icon (to be provided)

unraid-provisioner.plg            # installer, to be hosted at a stable URL
profiles/example-media-server.json
```

ets policy for sensitive environment variables
      in profiles (currently in plain text in the JSON)

#!/usr/bin/php
<?php
/**
 * Usage:
 *   php deploy.php /path/to/profile.json
 *   php deploy.php --url https://.../profile.json
 *   php deploy.php --name media-server-standard   (looks up local/library profiles)
 */

require_once '/usr/local/emhttp/plugins/unraid-provisioner/include/deploy-lib.php';

$args = $argv;
array_shift($args);

if (empty($args)) {
    fwrite(STDERR, "Usage: deploy.php <profile.json> | --url <url> | --name <profile-name>\n");
    exit(1);
}

try {
    if ($args[0] === '--url') {
        $path = provisioner_import_from_url($args[1]);
    } elseif ($args[0] === '--name') {
        $target = null;
        foreach (provisioner_list_profiles() as $p) {
            if ($p['name'] === $args[1]) { $target = $p['path']; break; }
        }
        if (!$target) {
            throw new RuntimeException("No known profile named '{$args[1]}'");
        }
        $path = $target;
    } else {
        $path = $args[0];
    }

    $profile = provisioner_load_profile($path);
    provisioner_deploy_profile($profile);
    echo "Profile '{$profile['name']}' deployed successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

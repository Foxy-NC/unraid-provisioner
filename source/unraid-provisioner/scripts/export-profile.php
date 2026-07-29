#!/usr/bin/php
<?php
/**
 * Usage: php export-profile.php <profile-name> [output-path]
 *
 * Captures the current host state (installed plugins + running containers)
 * into a profile JSON, so a reference server can be turned into a
 * redeployable "golden image" definition. Also available from the
 * webGui page ("Generate a profile from this server").
 */

require_once '/usr/local/emhttp/plugins/unraid-provisioner/include/deploy-lib.php';

$args = $argv;
array_shift($args);
$anonymize = false;
$args = array_values(array_filter($args, function ($a) use (&$anonymize) {
    if ($a === '--anonymize') { $anonymize = true; return false; }
    return true;
}));
if (empty($args)) {
    fwrite(STDERR, "Usage: export-profile.php <profile-name> [output-path] [--anonymize]\n");
    exit(1);
}

$name = $args[0];
$outputPath = $args[1] ?? null;

$profile = provisioner_export_profile($name, '', $anonymize);
$written = provisioner_save_profile($profile, $outputPath);

echo "Profile written to $written\n";
echo "Review it before reuse -- plugin URLs marked REPLACE_WITH_ACTUAL_URL_* need the real source URL.\n";

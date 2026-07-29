<?php
/**
 * Standalone download endpoint -- deliberately NOT a .page file, so it can
 * send raw headers (Content-Disposition) without any risk of the Dynamix
 * page framework having already started writing HTML output.
 * Served directly at /plugins/unraid-provisioner/download-profile.php
 */

require_once __DIR__ . '/include/deploy-lib.php';

// Never trust a raw path from the query string -- only accept a token that
// resolves to a path already returned by provisioner_list_profiles().
function provisioner_resolve_profile_token(string $token): ?array {
    $decoded = base64_decode($token, true);
    if ($decoded === false) return null;
    foreach (provisioner_list_profiles() as $p) {
        if ($p['path'] === $decoded) return $p;
    }
    return null;
}

$found = provisioner_resolve_profile_token($_GET['token'] ?? '');
if ($found === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Profile not found.";
    exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . basename($found['path']) . '"');
header('Content-Length: ' . filesize($found['path']));
readfile($found['path']);

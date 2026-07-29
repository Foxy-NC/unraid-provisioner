<?php
/**
 * Standalone view endpoint -- returns the raw JSON of a profile as plain
 * text, fetched via AJAX from the page's preview overlay. Deliberately not
 * a .page file, same reasoning as download-profile.php: no risk of the
 * Dynamix page framework having already written output before we set
 * headers, and no full-page navigation for what's meant to be an inline
 * preview.
 * Served directly at /plugins/unraid-provisioner/view-profile.php
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
readfile($found['path']);

<?php
/**
 * unraid-provisioner - deploy-lib.php
 *
 * Core provisioning engine. No external dependency beyond PHP (already
 * present on every Unraid host for the webGui) and the docker CLI.
 *
 * A "profile" is a JSON document listing plugins (.plg URLs) and containers
 * (plain docker-run parameters, not Community Applications templates) that
 * should exist on the host. Deployment is idempotent: anything already
 * present is left untouched.
 */

define('PROVISIONER_BASE', '/boot/config/plugins/unraid-provisioner');
define('PROVISIONER_LOCAL_PROFILES', PROVISIONER_BASE . '/profiles');
define('PROVISIONER_LIBRARY', PROVISIONER_BASE . '/library'); // git-imported profiles
define('PROVISIONER_LOG', PROVISIONER_BASE . '/deploy.log');
define('PROVISIONER_AUTODEPLOY_FLAG', PROVISIONER_BASE . '/autodeploy.json');
define('PROVISIONER_AUTODEPLOY_DONE', PROVISIONER_BASE . '/.autodeploy-done');

function provisioner_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(PROVISIONER_LOG, $line, FILE_APPEND);
    if (defined('STDERR')) {
        fwrite(STDERR, $line);
    }
}

function provisioner_run(string $cmd): array {
    provisioner_log("RUN: $cmd");
    exec($cmd . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        provisioner_log("  -> exit $exitCode: " . implode(' | ', $output));
    }
    return [$exitCode, $output];
}

/**
 * Load and validate a profile JSON file (local path or already-downloaded).
 */
function provisioner_load_profile(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("Profile not found: $path");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException("Profile is not valid JSON: $path");
    }
    foreach (['name', 'version'] as $required) {
        if (empty($data[$required])) {
            throw new RuntimeException("Profile missing required field '$required'");
        }
    }
    $data['plugins'] = $data['plugins'] ?? [];
    $data['containers'] = $data['containers'] ?? [];
    return $data;
}

/**
 * Fetch a profile from a raw URL (single JSON file) into the local library.
 */
function provisioner_import_from_url(string $url): string {
    if (!is_dir(PROVISIONER_LIBRARY)) {
        mkdir(PROVISIONER_LIBRARY, 0755, true);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'profile');
    [$code] = provisioner_run('curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg($tmp));
    if ($code !== 0) {
        throw new RuntimeException("Failed to fetch profile from $url");
    }
    $data = provisioner_load_profile($tmp);
    $dest = PROVISIONER_LIBRARY . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $data['name']) . '.json';
    rename($tmp, $dest);
    provisioner_log("Imported profile '{$data['name']}' from $url -> $dest");
    return $dest;
}

/**
 * Clone or pull a git repo of profiles (expects a profiles/*.json layout)
 * into the local library.
 */
function provisioner_sync_git_repo(string $repoUrl): void {
    $target = PROVISIONER_LIBRARY . '/_repo';
    if (is_dir($target . '/.git')) {
        provisioner_run('git -C ' . escapeshellarg($target) . ' pull --ff-only');
    } else {
        @mkdir(PROVISIONER_LIBRARY, 0755, true);
        provisioner_run('git clone --depth 1 ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($target));
    }
    foreach (glob($target . '/profiles/*.json') as $file) {
        copy($file, PROVISIONER_LIBRARY . '/' . basename($file));
    }
}

function provisioner_list_profiles(): array {
    $profiles = [];
    foreach ([PROVISIONER_LOCAL_PROFILES, PROVISIONER_LIBRARY] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.json') as $file) {
            try {
                $data = provisioner_load_profile($file);
                $profiles[] = ['path' => $file, 'source' => basename($dir)] + $data;
            } catch (Throwable $e) {
                provisioner_log("Skipping invalid profile $file: " . $e->getMessage());
            }
        }
    }
    return $profiles;
}

/**
 * Is this plugin already installed? Unraid records installed plugins under
 * /var/log/plugins/<name>.plg after a successful install.
 */
function provisioner_plugin_installed(string $name): bool {
    return is_file("/var/log/plugins/{$name}.plg");
}

function provisioner_install_plugin(array $plugin): void {
    $name = $plugin['name'] ?? basename($plugin['url']);
    if (provisioner_plugin_installed($name)) {
        provisioner_log("Plugin '$name' already installed, skipping.");
        return;
    }
    provisioner_log("Installing plugin '$name' from {$plugin['url']}");
    // 'plugin' is Unraid's built-in CLI installer, equivalent to pasting
    // the URL into Settings > Plugins > Install Plugin.
    provisioner_run('plugin install ' . escapeshellarg($plugin['url']));
}

function provisioner_container_exists(string $name): bool {
    [$code] = provisioner_run('docker inspect ' . escapeshellarg($name) . ' >/dev/null');
    return $code === 0;
}

/**
 * Build and run a `docker run -d` command directly from profile fields.
 * Deliberately independent of Community Applications templates: the
 * profile fully describes the container.
 */
function provisioner_deploy_container(array $c): void {
    $name = $c['name'];
    if (provisioner_container_exists($name)) {
        provisioner_log("Container '$name' already exists, skipping.");
        return;
    }

    $parts = ['docker run -d', '--name', escapeshellarg($name)];

    $parts[] = '--restart ' . escapeshellarg($c['restart_policy'] ?? 'unless-stopped');

    if (!empty($c['network_mode'])) {
        $parts[] = '--network ' . escapeshellarg($c['network_mode']);
    }

    foreach ($c['env'] ?? [] as $key => $value) {
        $parts[] = '-e ' . escapeshellarg("$key=$value");
    }

    foreach ($c['volumes'] ?? [] as $v) {
        $parts[] = '-v ' . escapeshellarg($v['host'] . ':' . $v['container'] . (($v['mode'] ?? '') ? ':' . $v['mode'] : ''));
    }

    if (($c['network_mode'] ?? '') !== 'host') {
        foreach ($c['ports'] ?? [] as $p) {
            $proto = $p['protocol'] ?? 'tcp';
            $parts[] = '-p ' . escapeshellarg($p['host'] . ':' . $p['container'] . '/' . $proto);
        }
    }

    foreach ($c['extra_args'] ?? [] as $arg) {
        $parts[] = $arg;
    }

    $parts[] = escapeshellarg($c['image']);

    provisioner_log("Deploying container '$name'");
    provisioner_run(implode(' ', $parts));
}

/**
 * Apply a full profile: install plugins, then deploy containers.
 * Idempotent — safe to run repeatedly (e.g. re-triggered manually).
 */
function provisioner_deploy_profile(array $profile): void {
    provisioner_log("=== Deploying profile '{$profile['name']}' (v{$profile['version']}) ===");
    foreach ($profile['plugins'] as $plugin) {
        provisioner_install_plugin($plugin);
    }
    foreach ($profile['containers'] as $container) {
        provisioner_deploy_container($container);
    }
    provisioner_log("=== Done: '{$profile['name']}' ===");
}

/**
 * --- Profile export (capture) ---
 *
 * Reads the current host state (installed plugins + running containers)
 * back into a profile array, so a reference server can be turned into a
 * redeployable "golden image" definition. Review the result before reuse:
 * inspect captures the container as it runs today, including any manual
 * tweaks made outside a profile, and plugin URLs aren't always
 * recoverable from the host alone.
 */
// Core system entries installed the same way as plugins (via /var/log/plugins/*.plg)
// but that aren't really optional software to provision -- excluded from
// every plugin listing/picker.
const PROVISIONER_EXCLUDED_PLUGINS = ['unRAIDServer'];

function provisioner_exported_plugins(): array {
    $plugins = [];
    foreach (glob('/var/log/plugins/*.plg') as $file) {
        $pluginName = basename($file, '.plg');
        if (in_array($pluginName, PROVISIONER_EXCLUDED_PLUGINS, true)) {
            continue;
        }
        $xml = @simplexml_load_file($file);
        $url = (string)($xml['pluginURL'] ?? '');
        $plugins[] = ['name' => $pluginName, 'url' => $url ?: "REPLACE_WITH_ACTUAL_URL_FOR_{$pluginName}"];
    }
    return $plugins;
}

function provisioner_exported_containers(): array {
    [$code, $names] = provisioner_run("docker ps -a --format '{{.Names}}'");
    $names = array_values(array_filter(array_map('trim', $names), fn($n) => $n !== ''));
    if (empty($names)) {
        return [];
    }

    // One docker inspect call for every container, instead of one call per
    // container -- with dozens of containers, N separate shell exec()s
    // dominate page load time; a single batched call returns the same
    // data as a JSON array in one process spawn.
    $quoted = implode(' ', array_map('escapeshellarg', $names));
    [$code, $jsonLines] = provisioner_run("docker inspect $quoted");
    $inspects = json_decode(implode("\n", $jsonLines), true) ?? [];

    $containers = [];
    foreach ($inspects as $inspect) {
        $cname = ltrim($inspect['Name'] ?? '', '/');
        if ($cname === '') continue;

        $env = [];
        foreach ($inspect['Config']['Env'] ?? [] as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'PATH') continue; // base-image plumbing, not worth capturing
            $env[$k] = $v;
        }

        $volumes = [];
        foreach ($inspect['Mounts'] ?? [] as $m) {
            if (($m['Type'] ?? '') !== 'bind') continue;
            $volumes[] = [
                'host' => $m['Source'],
                'container' => $m['Destination'],
                'mode' => ($m['RW'] ?? true) ? 'rw' : 'ro',
            ];
        }

        $ports = [];
        foreach ($inspect['HostConfig']['PortBindings'] ?? [] as $containerPort => $bindings) {
            [$port, $proto] = array_pad(explode('/', $containerPort), 2, 'tcp');
            foreach ($bindings as $b) {
                $ports[] = ['host' => (int)$b['HostPort'], 'container' => (int)$port, 'protocol' => $proto];
            }
        }

        $containers[] = [
            'name' => $cname,
            'image' => $inspect['Config']['Image'],
            'restart_policy' => $inspect['HostConfig']['RestartPolicy']['Name'] ?? 'unless-stopped',
            'network_mode' => $inspect['HostConfig']['NetworkMode'] ?? 'bridge',
            'env' => $env,
            'volumes' => $volumes,
            'ports' => $ports,
        ];
    }
    return $containers;
}

/**
 * Build a profile array from the current host state. Does not write
 * anything to disk — see provisioner_save_profile() for that.
 */
function provisioner_export_profile(string $name, string $description = '', bool $anonymize = false): array {
    $containers = provisioner_exported_containers();
    if ($anonymize) {
        $containers = provisioner_anonymize_containers($containers);
    }
    return [
        'name' => $name,
        'version' => date('Y.m.d'),
        'description' => $description ?: ('Exported from host on ' . date('Y-m-d H:i')),
        'plugins' => provisioner_exported_plugins(),
        'containers' => $containers,
    ];
}

/**
 * Persist a profile array as JSON under the local profiles directory
 * (or an explicit path). Returns the path written to.
 */
function provisioner_save_profile(array $profile, ?string $outputPath = null): string {
    $outputPath = $outputPath ?? (PROVISIONER_LOCAL_PROFILES . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $profile['name']) . '.json');
    if (!is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0755, true);
    }
    file_put_contents($outputPath, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    provisioner_log("Profile '{$profile['name']}' exported to $outputPath");
    return $outputPath;
}

/**
 * Build a profile from a hand-picked subset of this host's installed
 * plugins and existing containers -- as opposed to provisioner_export_profile(),
 * which always captures everything. Used by the webGui's profile builder,
 * where the user selects which items to include via checkboxes.
 */
function provisioner_build_profile(string $name, string $description, array $selectedPluginNames, array $selectedContainerNames, bool $anonymize = false): array {
    $plugins = array_values(array_filter(
        provisioner_exported_plugins(),
        fn($p) => in_array($p['name'], $selectedPluginNames, true)
    ));
    $containers = array_values(array_filter(
        provisioner_exported_containers(),
        fn($c) => in_array($c['name'], $selectedContainerNames, true)
    ));
    if ($anonymize) {
        $containers = provisioner_anonymize_containers($containers);
    }
    return [
        'name' => $name,
        'version' => date('Y.m.d'),
        'description' => $description ?: ('Custom profile built on ' . date('Y-m-d H:i')),
        'plugins' => $plugins,
        'containers' => $containers,
    ];
}

/**
 * --- Secret anonymization ---
 *
 * Redacts secrets when generating/building a profile, so the resulting
 * JSON is safe to commit to a shared/public profile repository. Applied
 * on request. Two best-effort layers, neither a guarantee -- review the
 * output before sharing it:
 *
 * 1. Environment keys whose NAME suggests a secret (password, token,
 *    API key, etc.) get a clearly-marked placeholder.
 * 2. Environment VALUES are scanned for well-known personal data
 *    regardless of the key they sit under: credentials embedded in a
 *    connection string (scheme://user:pass@host), email addresses,
 *    private (RFC1918) IPv4 addresses, and personal DDNS hostnames.
 */

// Known personal dynamic-DNS providers: a hostname under one of these is
// almost certainly the owner's own domain, so it is replaced with a
// clearly-fake placeholder (subdomain preserved) instead of shipping the
// real domain in a shared profile.
const PROVISIONER_DDNS_DOMAINS = [
    'duckdns.org', 'no-ip.org', 'no-ip.com', 'no-ip.biz',
    'dyndns.org', 'dynu.com', 'myfritz.net', 'zapto.org',
    'afraid.org', 'freeddns.org', 'ddns.net',
];

function provisioner_looks_like_secret_key(string $key): bool {
    return (bool)preg_match('/pass|pwd|secret|token|api[_-]?key|auth|credential/i', $key);
}

/**
 * Is this a private (RFC1918) IPv4 address? Loopback (127.0.0.0/8),
 * link-local (169.254.0.0/16), public and bind addresses (0.0.0.0) are
 * not private and return false, so they are never rewritten.
 */
function provisioner_is_private_ipv4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
}

/**
 * Map a private IPv4 address to a fake one, consistently for the whole
 * export so the same host keeps the same placeholder everywhere. Never
 * returns the original address back.
 */
function provisioner_private_ipv4_placeholder(string $ip, array &$ipMap): string {
    if (!isset($ipMap[$ip])) {
        $n = count($ipMap);
        do {
            $n++;
            $candidate = '192.168.' . (int)(($n - 1) / 255) . '.' . (($n - 1) % 255 + 1);
        } while ($candidate === $ip);
        $ipMap[$ip] = $candidate;
    }
    return $ipMap[$ip];
}

/**
 * Redact well-known personal data found in a single env VALUE. $emailMap
 * and $ipMap are shared across the whole export so the same original
 * address/IP always maps to the same placeholder. Returns the (possibly
 * unchanged) value.
 */
function provisioner_anonymize_value(string $value, array &$emailMap, array &$ipMap): string {
    // Credentials embedded in a connection string: scheme://user:pass@host.
    $value = preg_replace('~(://[^/@\s:]+):[^@\s]+@~', '$1:CHANGE_ME@', $value);
    // Private IPv4 addresses -> a consistently-mapped fake private address.
    $value = preg_replace_callback(
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        function (array $m) use (&$ipMap): string {
            return provisioner_is_private_ipv4($m[0])
                ? provisioner_private_ipv4_placeholder($m[0], $ipMap)
                : $m[0];
        },
        $value
    );
    // Email addresses -> a consistent per-address placeholder. Addresses
    // already under a reserved example.* domain are left untouched.
    $value = preg_replace_callback(
        '/\b[A-Za-z0-9._%+-]+@(?!example\.(?:com|org|net)\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
        function (array $m) use (&$emailMap): string {
            $addr = strtolower($m[0]);
            if (!isset($emailMap[$addr])) {
                $emailMap[$addr] = 'user' . (count($emailMap) + 1) . '@example.com';
            }
            return $emailMap[$addr];
        },
        $value
    );
    // Personal DDNS hostname -> example.duckdns.org, preserving subdomains.
    $ddns = implode('|', array_map('preg_quote', PROVISIONER_DDNS_DOMAINS));
    $value = preg_replace("~((?:[A-Za-z0-9-]+\\.)*)[A-Za-z0-9-]+\\.(?:{$ddns})~i", '$1example.duckdns.org', $value);
    return $value;
}

function provisioner_anonymize_containers(array $containers): array {
    $emailMap = [];
    $ipMap = [];
    foreach ($containers as &$c) {
        foreach ($c['env'] ?? [] as $key => $value) {
            if (provisioner_looks_like_secret_key($key)) {
                $c['env'][$key] = 'CHANGE_ME';
            } else {
                $c['env'][$key] = provisioner_anonymize_value((string)$value, $emailMap, $ipMap);
            }
        }
    }
    unset($c);
    return $containers;
}

/**
 * --- Category detection (display/filtering only) ---
 *
 * Best-effort category lookup, used by the webGui to group and filter the
 * plugin/container picker lists. Not part of the deployed profile schema.
 */
function provisioner_container_category_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $templatesDir = '/boot/config/plugins/dockerMan/templates-user';
    if (is_dir($templatesDir)) {
        foreach (glob($templatesDir . '/*.xml') as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml) continue;
            $name = (string)($xml->Name ?? '');
            if ($name === '') continue;
            $cat = trim((string)($xml->Category ?? ''), " \t\n\r\0\x0B:");
            if ($cat !== '') {
                // A template can list several colon-separated categories
                // (e.g. "MediaApp: Tools:") -- use the first as the
                // primary category for filtering purposes.
                $first = trim(explode(':', $cat)[0]);
                $map[$name] = $first !== '' ? $first : 'Uncategorized';
            } else {
                $map[$name] = 'Uncategorized';
            }
        }
    }
    return $map;
}

function provisioner_container_category(string $containerName): string {
    $map = provisioner_container_category_map();
    return $map[$containerName] ?? 'Uncategorized';
}

function provisioner_plugin_category(string $pluginFile): string {
    $xml = @simplexml_load_file($pluginFile);
    if ($xml) {
        $cat = trim((string)($xml['category'] ?? ''));
        if ($cat !== '') return $cat;
    }
    return 'Uncategorized';
}

/**
 * --- Description detection (tooltips only) ---
 *
 * Best-effort short description used as a hover tooltip in the webGui's
 * plugin/container picker lists. Not part of the deployed profile schema.
 */
function provisioner_container_description_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $templatesDir = '/boot/config/plugins/dockerMan/templates-user';
    if (is_dir($templatesDir)) {
        foreach (glob($templatesDir . '/*.xml') as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml) continue;
            $name = (string)($xml->Name ?? '');
            if ($name === '') continue;
            $overview = trim((string)($xml->Overview ?? ''));
            if ($overview !== '') {
                $map[$name] = $overview;
            }
        }
    }
    return $map;
}

function provisioner_container_description(string $containerName, string $fallback = ''): string {
    $map = provisioner_container_description_map();
    return $map[$containerName] ?? $fallback;
}

function provisioner_plugin_description(string $pluginFile, string $fallback = ''): string {
    // Installed plugins carry their description in a README.md rendered on
    // the Plugins page; that is the most reliable text source. Fall back to
    // a non-standard <description> attribute, then to $fallback.
    $xml = @simplexml_load_file($pluginFile);
    // The install directory follows the "name" declared in the .plg (e.g.
    // file unraid-r8125.plg declares name "r8125-driver"), not necessarily
    // the .plg file name itself; internal entities like &name; are resolved.
    $pluginName = $xml ? trim((string)($xml['name'] ?? '')) : '';
    if ($pluginName === '') {
        $pluginName = basename($pluginFile, '.plg');
    }
    $readme = "/usr/local/emhttp/plugins/{$pluginName}/README.md";
    if (is_file($readme)) {
        $desc = provisioner_readme_first_paragraph($readme);
        if ($desc !== '') {
            $title = provisioner_readme_title($readme);
            return ($title !== '' ? "{$title}: {$desc}" : $desc);
        }
    }
    if ($xml) {
        $desc = trim((string)($xml['description'] ?? ''));
        if ($desc !== '') return $desc;
    }
    return $fallback;
}

function provisioner_readme_title(string $readme): string {
    $content = @file_get_contents($readme);
    if ($content === false) {
        return '';
    }
    foreach (preg_split('/\R/', $content) as $raw) {
        $line = trim($raw);
        if ($line === '' || strpos($line, '<!--') === 0) continue;
        if (preg_match('/^#+/u', $line)) {
            $title = preg_replace('/^#+\s*/u', '', $line);
            $title = preg_replace('/#+\s*$/u', '', $title);
            return trim($title);
        }
        if (preg_match('/^<([a-z][a-z0-9]*)(?:\s[^>]*)?>(.*?)<\/\1>$/iu', $line, $m)) {
            $title = preg_replace('/<[^>]+>/u', '', $m[2]);
            return trim($title);
        }
        if (preg_match('/^(?:\*\*|__)(.+)(?:\*\*|__)$/u', $line, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^\*(.+)\*$/u', $line, $m)) {
            return trim($m[1]);
        }
        return '';
    }
    return '';
}

function provisioner_readme_first_paragraph(string $readme): string {
    $content = @file_get_contents($readme);
    if ($content === false) {
        return '';
    }
    $paragraph = '';
    $inCode = false;
    foreach (preg_split('/\R/', $content) as $raw) {
        $line = trim($raw);
        if ($inCode) {
            if (preg_match('/^(?:```|~~~)\s*$/', $line)) {
                $inCode = false;
            }
            continue;
        }
        if ($line === '') {
            if ($paragraph !== '') break;
            continue;
        }
        if (preg_match('/^(?:```|~~~)/', $line)) {
            $inCode = true;
            continue;
        }
        if (preg_match('/^#+/u', $line)) continue;
        if (preg_match('/^!\[/u', $line)) continue;
        if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/u', $line)) continue;
        if (preg_match('/^<([a-z][a-z0-9]*)(?:\s[^>]*)?>(.*?)<\/\1>$/iu', $line)) continue;
        if (preg_match('/^(?:\*\*|__)(.+)(?:\*\*|__)$/u', $line)) continue;
        if (preg_match('/^\*(.+)\*$/u', $line)) continue;
        $line = preg_replace('/<[^>]+>/u', '', $line);
        $line = preg_replace('/`[^`]*`/u', '', $line);
        $line = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $line);
        $line = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $line);
        $line = preg_replace('/[*_~`]/u', '', $line);
        $line = trim($line);
        if ($line === '') continue;
        $paragraph .= ($paragraph !== '' ? ' ' : '') . $line;
        if (strlen($paragraph) >= 300) break;
    }
    if (strlen($paragraph) > 300) {
        $words = preg_split('/\s+/u', $paragraph);
        $paragraph = '';
        foreach ($words as $word) {
            $candidate = ($paragraph !== '' ? ' ' : '') . $word;
            if (strlen($paragraph . $candidate) > 300) break;
            $paragraph .= $candidate;
        }
    }
    return $paragraph;
}

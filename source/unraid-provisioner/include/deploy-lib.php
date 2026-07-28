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
function provisioner_exported_plugins(): array {
    $plugins = [];
    foreach (glob('/var/log/plugins/*.plg') as $file) {
        $pluginName = basename($file, '.plg');
        $xml = @simplexml_load_file($file);
        $url = (string)($xml['pluginURL'] ?? '');
        $plugins[] = ['name' => $pluginName, 'url' => $url ?: "REPLACE_WITH_ACTUAL_URL_FOR_{$pluginName}"];
    }
    return $plugins;
}

function provisioner_exported_containers(): array {
    [$code, $names] = provisioner_run("docker ps -a --format '{{.Names}}'");
    $containers = [];
    foreach ($names as $cname) {
        $cname = trim($cname);
        if ($cname === '') continue;
        [$code, $jsonLines] = provisioner_run('docker inspect ' . escapeshellarg($cname));
        $inspect = json_decode(implode("\n", $jsonLines), true)[0] ?? null;
        if (!$inspect) continue;

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
function provisioner_export_profile(string $name, string $description = ''): array {
    return [
        'name' => $name,
        'version' => date('Y.m.d'),
        'description' => $description ?: ('Exported from host on ' . date('Y-m-d H:i')),
        'plugins' => provisioner_exported_plugins(),
        'containers' => provisioner_exported_containers(),
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

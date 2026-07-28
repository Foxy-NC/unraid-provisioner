#!/usr/bin/php
<?php
/**
 * Usage: php export-profile.php <profile-name> [output-path]
 *
 * Captures the current host state (installed plugins + running containers)
 * into a profile JSON, so a reference server can be turned into a
 * redeployable "golden image" definition.
 *
 * Containers are captured via `docker inspect`, translating their current
 * runtime config back into the profile's plain docker-run fields. Review
 * the output before reusing it — inspect captures the container as it runs
 * today, including any manual tweaks made outside a profile.
 */

require_once '/usr/local/emhttp/plugins/unraid-provisioner/include/deploy-lib.php';

$args = $argv;
array_shift($args);
if (empty($args)) {
    fwrite(STDERR, "Usage: export-profile.php <profile-name> [output-path]\n");
    exit(1);
}

$name = $args[0];
$outputPath = $args[1] ?? (PROVISIONER_LOCAL_PROFILES . "/$name.json");

function exported_plugins(): array {
    $plugins = [];
    foreach (glob('/var/log/plugins/*.plg') as $file) {
        $pluginName = basename($file, '.plg');
        // Best effort: the pluginURL used for the original install isn't
        // always recoverable from the installed .plg alone; if this plugin
        // was itself deployed via a provisioner profile, prefer copying the
        // original URL from that profile instead of relying on this guess.
        $xml = @simplexml_load_file($file);
        $url = (string)($xml['pluginURL'] ?? '');
        $plugins[] = ['name' => $pluginName, 'url' => $url ?: "REPLACE_WITH_ACTUAL_URL_FOR_{$pluginName}"];
    }
    return $plugins;
}

function exported_containers(): array {
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
            // Skip PATH and other base-image plumbing vars, keep the rest.
            if ($k === 'PATH') continue;
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

$profile = [
    'name' => $name,
    'version' => date('Y.m.d'),
    'description' => "Exported from host on " . date('Y-m-d H:i'),
    'plugins' => exported_plugins(),
    'containers' => exported_containers(),
];

if (!is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0755, true);
}
file_put_contents($outputPath, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Profile written to $outputPath\n";
echo "Review it before reuse -- plugin URLs marked REPLACE_WITH_ACTUAL_URL_* need the real source URL.\n";

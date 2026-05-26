<?php

declare(strict_types=1);

/**
 * Shared helpers for bootstrap inventory lint snapshot drift guard (#2210).
 */

function bootstrap_inventory_lint_extract_json_payload(string $stdout, string $stderr): string
{
    foreach ([$stdout, $stderr] as $stream) {
        $start = strpos($stream, '{');
        if (false !== $start) {
            return substr($stream, $start);
        }
    }

    throw new RuntimeException('lint --bootstrap-inventory --json: no JSON object in output');
}

/**
 * @return array{files: array<string, list<string>>}
 */
function bootstrap_inventory_lint_live_report(string $root): array
{
    $lint = $root.'/bin/lint.php';
    if (!is_readable($lint)) {
        throw new RuntimeException('missing bin/lint.php');
    }

    $cmd = [PHP_BINARY, $lint, '--bootstrap-inventory', '--json'];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to spawn lint --bootstrap-inventory');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (0 !== $code) {
        throw new RuntimeException(
            'lint --bootstrap-inventory failed: '.trim((string) $stderr."\n".(string) $stdout)
        );
    }

    $jsonText = bootstrap_inventory_lint_extract_json_payload((string) $stdout, (string) $stderr);
    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
        throw new RuntimeException('lint --bootstrap-inventory --json: invalid payload');
    }

    /** @var array<string, list<string>> $files */
    $files = [];
    foreach ($decoded['files'] as $rel => $kinds) {
        if (!is_string($rel) || !is_array($kinds)) {
            throw new RuntimeException('lint --bootstrap-inventory --json: malformed files entry');
        }
        $normalized = array_values(array_unique(array_map('strval', $kinds)));
        sort($normalized, SORT_STRING);
        $files[$rel] = $normalized;
    }
    ksort($files, SORT_STRING);

    return ['files' => $files];
}

/**
 * @param array{files: array<string, list<string>>} $report
 */
function bootstrap_inventory_lint_normalize_report(array $report): string
{
    return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * @return array{files: array<string, list<string>>}
 */
function bootstrap_inventory_lint_read_snapshot(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("missing snapshot: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
        throw new RuntimeException("invalid snapshot JSON: {$path}");
    }

    /** @var array<string, list<string>> $files */
    $files = [];
    foreach ($decoded['files'] as $rel => $kinds) {
        if (!is_string($rel) || !is_array($kinds)) {
            throw new RuntimeException("invalid snapshot files entry: {$path}");
        }
        $normalized = array_values(array_unique(array_map('strval', $kinds)));
        sort($normalized, SORT_STRING);
        $files[$rel] = $normalized;
    }
    ksort($files, SORT_STRING);

    return ['files' => $files];
}

/**
 * @param array{files: array<string, list<string>>} $live
 * @param array{files: array<string, list<string>>} $snapshot
 *
 * @return list<string>
 */
function bootstrap_inventory_lint_diff_errors(array $live, array $snapshot): array
{
    $errors = [];
    $liveFiles = $live['files'];
    $snapFiles = $snapshot['files'];

    $added = array_diff_key($liveFiles, $snapFiles);
    $removed = array_diff_key($snapFiles, $liveFiles);
    foreach ($added as $rel => $kinds) {
        $errors[] = "new unsupported file: {$rel} (".implode(', ', $kinds).')';
    }
    foreach ($removed as $rel => $kinds) {
        $errors[] = "resolved file missing from snapshot: {$rel} (was: ".implode(', ', $kinds).')';
    }
    foreach (array_intersect_key($liveFiles, $snapFiles) as $rel => $kinds) {
        if ($kinds !== $snapFiles[$rel]) {
            $errors[] = "kinds drift for {$rel}: live [".implode(', ', $kinds).'] vs snapshot ['.implode(', ', $snapFiles[$rel]).']';
        }
    }

    return $errors;
}

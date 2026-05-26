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
/**
 * Live lint report, or committed snapshot when lint cannot run (#2254 harness fallback).
 *
 * @return array{files: array<string, list<string>>, source: string}
 */
function bootstrap_inventory_lint_report_for_triage(string $root): array
{
    try {
        $live = bootstrap_inventory_lint_live_report($root);

        return ['files' => $live['files'], 'source' => 'live'];
    } catch (Throwable $e) {
        $snapshot = $root.'/docs/bootstrap-inventory-lint-snapshot.json';
        if (!is_readable($snapshot)) {
            throw $e;
        }
        $fromSnapshot = bootstrap_inventory_lint_read_snapshot($snapshot);

        return ['files' => $fromSnapshot['files'], 'source' => 'snapshot'];
    }
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
/**
 * Rank CFG gap kinds from a lint --bootstrap-inventory report (#2254).
 *
 * @param array{files: array<string, list<string>>} $report
 *
 * @return list<array{message: string, file_count: int, examples: list<string>, issue: ?int}>
 */
function bootstrap_inventory_lint_triage_rows(array $report, int $top = 20): array
{
    /** @var array<string, list<string>> $byMessage */
    $byMessage = [];
    foreach ($report['files'] as $rel => $kinds) {
        foreach ($kinds as $kind) {
            $byMessage[$kind][] = $rel;
        }
    }
    $rows = [];
    foreach ($byMessage as $message => $paths) {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $rows[] = [
            'message' => $message,
            'file_count' => count($paths),
            'examples' => array_slice($paths, 0, 3),
            'issue' => bootstrap_inventory_triage_tracking_issue($message),
        ];
    }
    usort(
        $rows,
        static function (array $a, array $b): int {
            $cmp = $b['file_count'] <=> $a['file_count'];
            if (0 !== $cmp) {
                return $cmp;
            }

            return strcmp($a['message'], $b['message']);
        }
    );

    if ($top > 0) {
        $rows = array_slice($rows, 0, $top);
    }
    foreach ($rows as $i => $row) {
        $rows[$i]['rank'] = $i + 1;
    }

    return $rows;
}

function bootstrap_inventory_triage_tracking_issue(string $message): ?int
{
    if (str_starts_with($message, 'Unknown Stmt Type')) {
        return 2276;
    }
    if (str_starts_with($message, 'Unsupported unset target')) {
        return 2273;
    }

    return null;
}

/**
 * @param list<array{rank: int, message: string, file_count: int, examples: list<string>, issue: ?int}> $rows
 */
function bootstrap_inventory_lint_triage_render_table(array $rows): string
{
    if ([] === $rows) {
        return "  (no CFG gaps — lint --bootstrap-inventory is clean)\n";
    }
    $lines = [];
    $lines[] = '  | Rank | CFG gap | Files | Example paths |';
    $lines[] = '  |-----:|---------|------:|---------------|';
    foreach ($rows as $row) {
        $issueSuffix = null !== $row['issue'] ? ' #'.$row['issue'] : '';
        $gap = $row['message'].$issueSuffix;
        $examples = implode(', ', array_map(
            static fn (string $p): string => '`'.$p.'`',
            $row['examples']
        ));
        if ('' === $examples) {
            $examples = '—';
        }
        $lines[] = sprintf(
            '  | %d | `%s` | %d | %s |',
            $row['rank'],
            str_replace('`', '\\`', $row['message']),
            $row['file_count'],
            $examples
        );
    }

    return implode("\n", $lines)."\n";
}

/**
 * @param list<array{rank: int, message: string, file_count: int, examples: list<string>, issue: ?int}> $rows
 */
function bootstrap_inventory_lint_triage_render_json(array $rows, int $scanned, int $top): string
{
    return json_encode(
        [
            'scanned' => $scanned,
            'top' => $top,
            'rows' => $rows,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )."\n";
}

/** Committed triage snapshot row count (#2265). */
const BOOTSTRAP_INVENTORY_TRIAGE_SYNC_TOP = 50;

/**
 * Live triage payload for drift guard (#2265); matches bootstrap-inventory-triage.php --json.
 *
 * @return array{scanned: int, top: int, rows: list<array{rank: int, message: string, file_count: int, examples: list<string>, issue: ?int}>}
 */
function bootstrap_inventory_triage_live_payload(string $root, int $top = BOOTSTRAP_INVENTORY_TRIAGE_SYNC_TOP): array
{
    $bundle = bootstrap_inventory_lint_report_for_triage($root);
    $report = ['files' => $bundle['files']];
    $scanned = count(bootstrapVmPathPhpFiles($root));
    $rows = bootstrap_inventory_lint_triage_rows($report, $top);

    return [
        'scanned' => $scanned,
        'top' => $top,
        'rows' => $rows,
    ];
}

/**
 * @param array{scanned?: mixed, top?: mixed, rows?: mixed, source?: mixed} $payload
 *
 * @return array{scanned: int, top: int, rows: list<array<string, mixed>>}
 */
function bootstrap_inventory_triage_normalize_payload(array $payload): array
{
    if (!isset($payload['rows']) || !is_array($payload['rows'])) {
        throw new RuntimeException('triage payload: missing rows');
    }
    if (!isset($payload['scanned']) || !is_numeric($payload['scanned'])) {
        throw new RuntimeException('triage payload: missing scanned');
    }
    if (!isset($payload['top']) || !is_numeric($payload['top'])) {
        throw new RuntimeException('triage payload: missing top');
    }

    return [
        'scanned' => (int) $payload['scanned'],
        'top' => (int) $payload['top'],
        'rows' => $payload['rows'],
    ];
}

/**
 * @param array{scanned: int, top: int, rows: list<array<string, mixed>>} $live
 * @param array{scanned: int, top: int, rows: list<array<string, mixed>>} $snapshot
 *
 * @return list<string>
 */
function bootstrap_inventory_triage_diff_errors(array $live, array $snapshot): array
{
    $errors = [];
    if ($live['scanned'] !== $snapshot['scanned']) {
        $errors[] = "scanned {$live['scanned']} != snapshot {$snapshot['scanned']}";
    }
    if ($live['top'] !== $snapshot['top']) {
        $errors[] = "top {$live['top']} != snapshot {$snapshot['top']}";
    }
    $liveJson = json_encode($live['rows'], JSON_UNESCAPED_SLASHES);
    $snapJson = json_encode($snapshot['rows'], JSON_UNESCAPED_SLASHES);
    if ($liveJson !== $snapJson) {
        $errors[] = 'top CFG gap rows differ from docs/bootstrap-inventory-triage-top50.json';
    }

    return $errors;
}

/**
 * @return array{scanned: int, top: int, rows: list<array<string, mixed>>}
 */
function bootstrap_inventory_triage_read_snapshot(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("missing triage snapshot: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("invalid triage snapshot JSON: {$path}");
    }
    unset($decoded['source']);

    return bootstrap_inventory_triage_normalize_payload($decoded);
}

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

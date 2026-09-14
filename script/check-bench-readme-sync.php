<?php

declare(strict_types=1);

/**
 * Benchmark README honesty gate (#36385 Done-when).
 *
 * Timing figures in benchmarks/README.md and benchmarks/v2/README.md may appear
 * only inside the generated table markers. Both tables must match their
 * RESULTS.json (sprintf %.4f), not hand-typed drift. Chart HTML must match
 * generate-bench-chart.php (history + RESULTS ratchet).
 *
 * Usage:
 *   php script/check-bench-readme-sync.php
 *   php script/check-bench-readme-sync.php --render   # rewrite both tables from RESULTS.json
 *   php script/check-bench-readme-sync.php --self-test
 */

$root = dirname(__DIR__);
$argvList = array_slice($argv ?? [], 1);
$render = in_array('--render', $argvList, true);
$selfTest = in_array('--self-test', $argvList, true);

if ($selfTest) {
    exit(runSelfTest($root));
}

if ($render) {
    $rc = 0;
    $rc |= renderLegacyReadmeFromResults($root);
    $rc |= renderV2ReadmeFromResults($root);
    if (0 !== $rc) {
        exit($rc);
    }
}

exit(checkAll($root));

function checkAll(string $root): int
{
    $fail = 0;
    $fail |= checkLegacyReadme($root);
    $fail |= checkV2Readme($root);
    $fail |= checkBenchChartSync($root);
    if (0 === $fail) {
        fwrite(STDOUT, "check-bench-readme-sync: OK (#36385)\n");
    }

    return $fail;
}

function checkLegacyReadme(string $root): int
{
    $path = $root.'/benchmarks/README.md';
    $resultsPath = $root.'/benchmarks/RESULTS.json';
    if (!is_file($path)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$path}\n");

        return 1;
    }
    if (!is_file($resultsPath)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$resultsPath} (legacy RESULTS — regenerate via script/bench.php)\n");

        return 1;
    }
    $body = (string) file_get_contents($path);
    $doc = json_decode((string) file_get_contents($resultsPath), true);
    if (!is_array($doc) || !isset($doc['cases']) || !is_array($doc['cases'])) {
        fwrite(STDERR, "check-bench-readme-sync: invalid benchmarks/RESULTS.json\n");

        return 1;
    }
    if (!preg_match(
        '/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/s',
        $body,
        $m
    )) {
        fwrite(STDERR, "check-bench-readme-sync: benchmarks/README.md missing table markers\n");

        return 1;
    }
    $inside = $m[1];
    $outside = preg_replace(
        '/<!-- benchmark table start -->.*<!-- benchmark table end -->/s',
        '',
        $body
    ) ?? '';
    $fail = 0;
    $fail |= banHandTypedClaims($outside, 'benchmarks/README.md (outside table)');
    $fail |= banTimingDecimals($outside, 'benchmarks/README.md (outside table)');

    $cases = $doc['cases'];
    foreach (['Ack(3,10)', 'fibo(30)', 'mandelbrot', 'simple'] as $need) {
        if (!isset($cases[$need])) {
            fwrite(STDERR, "check-bench-readme-sync: RESULTS.json missing headline case {$need}\n");
            $fail = 1;
        }
    }

    $expectedRows = extractPipeRows(buildLegacyTableBody($doc));
    $actualRows = extractPipeRows($inside);
    if ($expectedRows !== $actualRows) {
        fwrite(STDERR, "check-bench-readme-sync: legacy README table drifts from RESULTS.json\n");
        fwrite(STDERR, "  fix: php script/check-bench-readme-sync.php --render\n");
        fwrite(STDERR, "  or:  PHP_8_2=\$(command -v php) php script/bench.php\n");
        $fail = 1;
        $max = max(\count($expectedRows), \count($actualRows));
        for ($i = 0; $i < $max; ++$i) {
            $e = $expectedRows[$i] ?? '<missing>';
            $a = $actualRows[$i] ?? '<missing>';
            if ($e !== $a) {
                fwrite(STDERR, "  row ".($i + 1).":\n    expected: {$e}\n    actual:   {$a}\n");
                if ($i >= 4) {
                    fwrite(STDERR, "  …\n");
                    break;
                }
            }
        }
    }

    return $fail;
}

function checkV2Readme(string $root): int
{
    $readmePath = $root.'/benchmarks/v2/README.md';
    $resultsPath = $root.'/benchmarks/v2/RESULTS.json';
    if (!is_file($readmePath)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$readmePath}\n");

        return 1;
    }
    if (!is_file($resultsPath)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$resultsPath}\n");

        return 1;
    }
    $body = (string) file_get_contents($readmePath);
    $doc = json_decode((string) file_get_contents($resultsPath), true);
    if (!is_array($doc) || !isset($doc['cases']) || !is_array($doc['cases'])) {
        fwrite(STDERR, "check-bench-readme-sync: invalid RESULTS.json\n");

        return 1;
    }
    if (!preg_match(
        '/<!-- v2 benchmark table start -->(.*)<!-- v2 benchmark table end -->/s',
        $body,
        $m
    )) {
        fwrite(STDERR, "check-bench-readme-sync: v2 README missing table markers\n");

        return 1;
    }
    $inside = $m[1];
    $outside = preg_replace(
        '/<!-- v2 benchmark table start -->.*<!-- v2 benchmark table end -->/s',
        '',
        $body
    ) ?? '';
    $fail = 0;
    $fail |= banHandTypedClaims($outside, 'benchmarks/v2/README.md (outside table)');
    $fail |= banTimingDecimals($outside, 'benchmarks/v2/README.md (outside table)');

    $cases = $doc['cases'];
    if (\count($cases) < 16) {
        fwrite(STDERR, 'check-bench-readme-sync: RESULTS.json has '.\count($cases)
            ." cases; need ≥16 (#36385)\n");
        $fail = 1;
    }

    $expectedTable = buildV2TableBody($doc);
    // Compare only the pipe-table rows (ignore Environment stamp / blank lines).
    $expectedRows = extractPipeRows($expectedTable);
    $actualRows = extractPipeRows($inside);
    if ($expectedRows !== $actualRows) {
        fwrite(STDERR, "check-bench-readme-sync: v2 README table drifts from RESULTS.json\n");
        fwrite(STDERR, "  fix: php script/check-bench-readme-sync.php --render\n");
        fwrite(STDERR, "  or:  PHP_8_2=\$(command -v php) php script/bench.php --v2\n");
        $fail = 1;
        $max = max(\count($expectedRows), \count($actualRows));
        for ($i = 0; $i < $max; ++$i) {
            $e = $expectedRows[$i] ?? '<missing>';
            $a = $actualRows[$i] ?? '<missing>';
            if ($e !== $a) {
                fwrite(STDERR, "  row ".($i + 1).":\n    expected: {$e}\n    actual:   {$a}\n");
                if ($i >= 4) {
                    fwrite(STDERR, "  …\n");
                    break;
                }
            }
        }
    }

    foreach (array_keys($cases) as $name) {
        if (!preg_match('/\|\s*'.preg_quote((string) $name, '/').'\s*\|/', $inside)) {
            fwrite(STDERR, "check-bench-readme-sync: v2 table missing case {$name}\n");
            $fail = 1;
        }
    }

    return $fail;
}

/**
 * Chart + history ratchet: committed bench.html must match regenerate, and
 * history/ must be non-empty with RESULTS embedded as latest (#36385).
 */
function checkBenchChartSync(string $root): int
{
    $historyDir = $root.'/benchmarks/history';
    $files = is_dir($historyDir) ? (glob($historyDir.'/*.json') ?: []) : [];
    if (\count($files) < 1) {
        fwrite(STDERR, "check-bench-readme-sync: benchmarks/history/ empty — run script/bench/nightly.sh --publish-only\n");

        return 1;
    }
    $resultsPath = $root.'/benchmarks/v2/RESULTS.json';
    if (!is_file($resultsPath)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$resultsPath} for chart ratchet\n");

        return 1;
    }
    $doc = json_decode((string) file_get_contents($resultsPath), true);
    if (!is_array($doc) || !isset($doc['generated_at']) || !is_string($doc['generated_at'])) {
        fwrite(STDERR, "check-bench-readme-sync: v2 RESULTS.json missing generated_at\n");

        return 1;
    }
    $htmlPath = $root.'/docs/pages/bench.html';
    if (!is_file($htmlPath)) {
        fwrite(STDERR, "check-bench-readme-sync: missing {$htmlPath}\n");

        return 1;
    }
    $html = (string) file_get_contents($htmlPath);
    if (!str_contains($html, $doc['generated_at'])) {
        fwrite(STDERR, "check-bench-readme-sync: bench.html missing RESULTS generated_at {$doc['generated_at']}\n");
        fwrite(STDERR, "  fix: php script/generate-bench-chart.php\n");

        return 1;
    }
    $php = \PHP_BINARY;
    $cmd = escapeshellcmd($php).' '.escapeshellarg($root.'/script/generate-bench-chart.php').' --check';
    exec($cmd.' 2>&1', $lines, $rc);
    if (0 !== $rc) {
        fwrite(STDERR, implode("\n", $lines)."\n");

        return 1;
    }

    return 0;
}

/**
 * @param array<string, mixed> $doc
 */
function buildLegacyTableBody(array $doc): string
{
    return buildSuiteTableBody($doc, '8.2');
}

/**
 * @param array<string, mixed> $doc
 */
function buildV2TableBody(array $doc): string
{
    return buildSuiteTableBody($doc, '8.2');
}

/**
 * Match script/bench.php table layout exactly (Zend runtime key "8.2").
 *
 * @param array<string, mixed> $doc
 */
function buildSuiteTableBody(array $doc, string $zendKey): string
{
    $phpVersion = (string) ($doc['php_version'] ?? 'unknown');
    $iterations = (int) ($doc['iterations'] ?? 3);
    $stamp = sprintf(
        "Environment: %s · LLVM 9 available · %d iterations averaged, wall time per run.\n\n",
        $phpVersion,
        $iterations
    );
    $header = '| Test Name          '.sprintf('| Zend %9s (s)', $zendKey)
        ."| bin/vm.php (s) | bin/jit.php (s) | phpc build (s) | native run (s) |\n";
    $header .= '|--------------------|'.str_repeat('-', 19)
        ."|----------------|-----------------|----------------|----------------|\n";
    $rows = '';
    $cases = $doc['cases'];
    if (!is_array($cases)) {
        return $stamp.$header;
    }
    ksort($cases, \SORT_STRING);
    foreach ($cases as $name => $resultset) {
        if (!is_array($resultset)) {
            continue;
        }
        $rows .= sprintf('| %18s ', (string) $name);
        $zend = $resultset[$zendKey] ?? null;
        $rows .= is_float($zend) || is_int($zend)
            ? sprintf('|      %12.4f ', (float) $zend)
            : sprintf('|      %12s ', 'n/a');
        foreach (['vm', 'jit', 'aotcompile', 'aot'] as $col) {
            $val = $resultset[$col] ?? null;
            $rows .= is_float($val) || is_int($val)
                ? sprintf('|   %12.4f ', (float) $val)
                : sprintf('|   %12s ', 'n/a');
        }
        $rows .= "|\n";
    }

    return $stamp.$header.$rows;
}

function extractPipeRows(string $block): array
{
    $rows = [];
    foreach (preg_split("/\r\n|\n|\r/", $block) ?: [] as $line) {
        $line = rtrim($line);
        if (str_starts_with($line, '|')) {
            $rows[] = $line;
        }
    }

    return $rows;
}

function renderLegacyReadmeFromResults(string $root): int
{
    $readmePath = $root.'/benchmarks/README.md';
    $resultsPath = $root.'/benchmarks/RESULTS.json';
    if (!is_file($resultsPath)) {
        fwrite(STDERR, "check-bench-readme-sync --render: missing {$resultsPath}\n");

        return 1;
    }
    $doc = json_decode((string) file_get_contents($resultsPath), true);
    if (!is_array($doc) || !isset($doc['cases']) || !is_array($doc['cases'])) {
        fwrite(STDERR, "check-bench-readme-sync --render: invalid benchmarks/RESULTS.json\n");

        return 1;
    }
    $readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';
    if (!str_contains($readme, '<!-- benchmark table start -->')) {
        $readme .= "\n<!-- benchmark table start -->\n\n<!-- benchmark table end -->\n";
    }
    $table = buildLegacyTableBody($doc);
    $readme = preg_replace(
        '((<!-- benchmark table start -->)(.*)(<!-- benchmark table end -->))ims',
        "\$1\n\n".$table."\n\$3",
        $readme
    );
    if (!is_string($readme)) {
        fwrite(STDERR, "check-bench-readme-sync --render: legacy preg_replace failed\n");

        return 1;
    }
    file_put_contents($readmePath, $readme);
    fwrite(STDOUT, "check-bench-readme-sync --render: wrote {$readmePath} from RESULTS.json\n");

    return 0;
}

function renderV2ReadmeFromResults(string $root): int
{
    $readmePath = $root.'/benchmarks/v2/README.md';
    $resultsPath = $root.'/benchmarks/v2/RESULTS.json';
    if (!is_file($resultsPath)) {
        fwrite(STDERR, "check-bench-readme-sync --render: missing {$resultsPath}\n");

        return 1;
    }
    $doc = json_decode((string) file_get_contents($resultsPath), true);
    if (!is_array($doc) || !isset($doc['cases']) || !is_array($doc['cases'])) {
        fwrite(STDERR, "check-bench-readme-sync --render: invalid RESULTS.json\n");

        return 1;
    }
    $readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';
    if (!str_contains($readme, '<!-- v2 benchmark table start -->')) {
        $readme .= "\n<!-- v2 benchmark table start -->\n\n<!-- v2 benchmark table end -->\n";
    }
    $table = buildV2TableBody($doc);
    $readme = preg_replace(
        '((<!-- v2 benchmark table start -->)(.*)(<!-- v2 benchmark table end -->))ims',
        "\$1\n\n".$table."\n\$3",
        $readme
    );
    if (!is_string($readme)) {
        fwrite(STDERR, "check-bench-readme-sync --render: preg_replace failed\n");

        return 1;
    }
    file_put_contents($readmePath, $readme);
    fwrite(STDOUT, "check-bench-readme-sync --render: wrote {$readmePath} from RESULTS.json\n");

    return 0;
}

function banHandTypedClaims(string $text, string $label): int
{
    $fail = 0;
    foreach ([
        '/\b\d+(?:\.\d+)?\s*[x×]\s*faster\b/iu',
        '/\b\d+(?:\.\d+)?\s*[x×]\s*slower\b/iu',
        '/\b\d+(?:\.\d+)?\s*[x×]\s*Zend\b/iu',
    ] as $re) {
        if (preg_match($re, $text, $m)) {
            fwrite(STDERR, "check-bench-readme-sync: hand-typed speed claim in {$label}: {$m[0]}\n");
            $fail = 1;
        }
    }

    return $fail;
}

function banTimingDecimals(string $text, string $label): int
{
    // 4+ decimal places are the bench.php table format — must not appear in prose.
    if (preg_match('/\b\d+\.\d{4,}\b/', $text, $m)) {
        fwrite(STDERR, "check-bench-readme-sync: timing-like number outside generated table in {$label}: {$m[0]}\n");

        return 1;
    }

    return 0;
}

function runSelfTest(string $root): int
{
    $tmp = sys_get_temp_dir().'/phpc-bench-readme-sync-'.getmypid();
    if (!mkdir($tmp.'/benchmarks/v2', 0777, true) && !is_dir($tmp.'/benchmarks/v2')) {
        fwrite(STDERR, "check-bench-readme-sync --self-test: mkdir failed\n");

        return 1;
    }
    if (!mkdir($tmp.'/benchmarks/history', 0777, true) && !is_dir($tmp.'/benchmarks/history')) {
        fwrite(STDERR, "check-bench-readme-sync --self-test: history mkdir failed\n");

        return 1;
    }
    if (!mkdir($tmp.'/docs/pages', 0777, true) && !is_dir($tmp.'/docs/pages')) {
        fwrite(STDERR, "check-bench-readme-sync --self-test: docs mkdir failed\n");

        return 1;
    }
    // Point chart check at real scripts but skip full checkAll chart path in unit isolation —
    // self-test covers legacy/v2 table honesty; chart ratchet is exercised on the real tree.
    try {
        $results = [
            'version' => 1,
            'suite' => 'v2',
            'generated_at' => '2026-01-01T00:00:00Z',
            'php_version' => '8.2.32',
            'iterations' => 3,
            'cases' => [],
        ];
        for ($i = 1; $i <= 16; ++$i) {
            $results['cases']['case-'.$i] = [
                '8.2' => 0.01 * $i,
                'vm' => 0.1 * $i,
                'jit' => 0.11 * $i,
                'aotcompile' => 0.2,
                'aot' => 0.005 * $i,
            ];
        }
        file_put_contents(
            $tmp.'/benchmarks/v2/RESULTS.json',
            json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n"
        );
        $legacy = [
            'version' => 1,
            'suite' => 'legacy',
            'generated_at' => '2026-01-01T00:00:00Z',
            'php_version' => '8.2.32',
            'iterations' => 5,
            'cases' => [
                'Ack(3,10)' => ['8.2' => 1.5937, 'vm' => null, 'jit' => null, 'aotcompile' => 8.4420, 'aot' => 4.1176],
                'fibo(30)' => ['8.2' => 0.1008, 'vm' => null, 'jit' => null, 'aotcompile' => 8.4598, 'aot' => 0.0170],
                'mandelbrot' => ['8.2' => 0.1518, 'vm' => null, 'jit' => null, 'aotcompile' => 8.4498, 'aot' => 0.1699],
                'simple' => ['8.2' => 0.0668, 'vm' => null, 'jit' => null, 'aotcompile' => 8.2235, 'aot' => 0.5087],
            ],
        ];
        file_put_contents(
            $tmp.'/benchmarks/RESULTS.json',
            json_encode($legacy, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n"
        );
        $goodV2 = buildV2TableBody($results);
        $goodLegacy = buildLegacyTableBody($legacy);
        file_put_contents(
            $tmp.'/benchmarks/v2/README.md',
            "# v2\n\nProse without timings.\n\n"
            ."<!-- v2 benchmark table start -->\n\n{$goodV2}\n<!-- v2 benchmark table end -->\n"
        );
        file_put_contents(
            $tmp.'/benchmarks/README.md',
            "# Benchmarks\n\nDo not hand-edit.\n\n"
            ."<!-- benchmark table start -->\n\n{$goodLegacy}\n<!-- benchmark table end -->\n"
        );

        if (0 !== checkLegacyReadme($tmp) || 0 !== checkV2Readme($tmp)) {
            fwrite(STDERR, "check-bench-readme-sync --self-test: FAIL — clean tree should pass\n");

            return 1;
        }

        // Hand-typed claim outside markers must fail.
        file_put_contents(
            $tmp.'/benchmarks/README.md',
            "# Benchmarks\n\nAOT is 9.1x faster than Zend.\n\n"
            ."<!-- benchmark table start -->\n\n{$goodLegacy}\n<!-- benchmark table end -->\n"
        );
        if (0 === checkLegacyReadme($tmp)) {
            fwrite(STDERR, "check-bench-readme-sync --self-test: FAIL — hand-typed '9.1x faster' not caught\n");

            return 1;
        }

        // Drift between RESULTS.json and legacy table must fail.
        file_put_contents(
            $tmp.'/benchmarks/README.md',
            "# ok\n\n<!-- benchmark table start -->\n\n"
            .str_replace('1.5937', '9.9999', $goodLegacy)
            ."\n<!-- benchmark table end -->\n"
        );
        if (0 === checkLegacyReadme($tmp)) {
            fwrite(STDERR, "check-bench-readme-sync --self-test: FAIL — legacy RESULTS drift not caught\n");

            return 1;
        }

        // Drift between RESULTS.json and v2 table must fail.
        file_put_contents(
            $tmp.'/benchmarks/README.md',
            "# ok\n\n<!-- benchmark table start -->\n\n{$goodLegacy}\n<!-- benchmark table end -->\n"
        );
        $drifted = str_replace('0.0100', '9.9999', "# v2\n\nProse without timings.\n\n"
            ."<!-- v2 benchmark table start -->\n\n{$goodV2}\n<!-- v2 benchmark table end -->\n");
        file_put_contents($tmp.'/benchmarks/v2/README.md', $drifted);
        if (0 === checkV2Readme($tmp)) {
            fwrite(STDERR, "check-bench-readme-sync --self-test: FAIL — RESULTS drift not caught\n");

            return 1;
        }

        fwrite(STDOUT, "check-bench-readme-sync --self-test: OK\n");

        return 0;
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($tmp);
    }
}

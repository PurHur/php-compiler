#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 006-FileUploadWeb run matrix + benchmark row (issues #2018, #2027).
 *
 * Run matrix vs ci-defaults.env:
 *   FILE_UPLOAD_WEB_SMOKE_GATE, FILE_UPLOAD_WEB_AOT_LINK_GATE, FILE_UPLOAD_WEB_AOT_SMOKE_GATE
 *
 * Benchmark row policy matches script/rebuild-examples.php (#2027):
 *   - Include when BENCH_FILEUPLOADWEB=1 or phpc lint --all examples/006-FileUploadWeb passes
 *   - AOT columns: n/a when LLVM/multipart probe fails; real timings when probe passes
 *
 * Usage:
 *   php script/check-rebuild-examples-006-row.php
 */

$root = dirname(__DIR__);
$readme = $root.'/examples/README.md';
$exampleDir = $root.'/examples/006-FileUploadWeb';
$example = $exampleDir.'/example.php';

if (!is_file($example)) {
    fwrite(STDOUT, "check-rebuild-examples-006-row: OK (006-FileUploadWeb tree absent)\n");
    exit(0);
}

if (!is_readable($readme)) {
    fwrite(STDERR, "check-rebuild-examples-006-row: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$errors = [];

if (!preg_match('/\| \[006-FileUploadWeb\]/', $body)) {
    $errors[] = 'examples/README.md: run matrix missing [006-FileUploadWeb] row (tree exists; see #1999)';
}

$expectBenchRow = should_expect_fileupload_benchmark_row($root);
$hasBenchRow = benchmark_table_has_fileupload_web_row($body);

if ($expectBenchRow && !$hasBenchRow) {
    $errors[] = 'examples/README.md: benchmark table missing 006-FileUploadWeb row (lint green or BENCH_FILEUPLOADWEB=1; run: ./script/rebuild-examples.php)';
}

if (!$expectBenchRow && $hasBenchRow) {
    $errors[] = 'examples/README.md: benchmark table has stale 006-FileUploadWeb row (lint failing; remove row or fix lint; FILEUPLOADWEB_LINT_GATE=0 only for rebuild script)';
}

if ($hasBenchRow) {
    $benchLine = extract_fileupload_web_benchmark_line($body);
    if (null !== $benchLine && !fileupload_benchmark_row_aot_columns_honest($benchLine, $root)) {
        $errors[] = 'examples/README.md: 006-FileUploadWeb benchmark AOT columns out of sync (run: BENCH_FILEUPLOADWEB=1 BENCH_FILEUPLOADWEB_AOT=1 ./script/rebuild-examples.php; #2027)';
    }
}

$matrixLine = extract_fileupload_run_matrix_line($body);
$section = extract_fileupload_section($body);

if (null === $matrixLine) {
    $errors[] = 'examples/README.md: could not parse 006-FileUploadWeb run matrix row';
}

if (null === $section) {
    $errors[] = 'examples/README.md: missing ### 006-FileUploadWeb section';
}

$smokeDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_SMOKE_GATE');
$linkDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_LINK_GATE');
$aotDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE');

if (null !== $section) {
    gate_doc_errors(
        $errors,
        'VM multipart',
        'FILE_UPLOAD_WEB_SMOKE_GATE',
        $smokeDefault,
        $section,
        [
            'on' => ['FILE_UPLOAD_WEB_SMOKE_GATE=1', 'default `FILE_UPLOAD_WEB_SMOKE_GATE=1`'],
            'off' => ['FILE_UPLOAD_WEB_SMOKE_GATE=0', 'opt-in'],
        ]
    );
    gate_doc_errors(
        $errors,
        'AOT link',
        'FILE_UPLOAD_WEB_AOT_LINK_GATE',
        $linkDefault,
        $section,
        [
            'on' => ['FILE_UPLOAD_WEB_AOT_LINK_GATE=1', 'default `FILE_UPLOAD_WEB_AOT_LINK_GATE=1`'],
            'off' => ['FILE_UPLOAD_WEB_AOT_LINK_GATE=0', 'opt-in'],
        ]
    );
    gate_doc_errors(
        $errors,
        'AOT execute',
        'FILE_UPLOAD_WEB_AOT_SMOKE_GATE',
        $aotDefault,
        $section,
        [
            'on' => ['FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1', 'default `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1`'],
            'off' => ['FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0', 'opt-in'],
        ]
    );
}

if (null !== $matrixLine) {
    if ('1' === $linkDefault) {
        if (!preg_match('/phpc build/i', $matrixLine) || !preg_match('/#2011/i', $matrixLine)) {
            $errors[] = 'examples/README.md: 006 run matrix AOT build column should document phpc build link (#2011) when FILE_UPLOAD_WEB_AOT_LINK_GATE default is 1';
        }
        if (preg_match('/link\s+opt-in/i', $matrixLine)) {
            $errors[] = 'examples/README.md: 006 run matrix AOT build says link opt-in but FILE_UPLOAD_WEB_AOT_LINK_GATE default is 1';
        }
    } else {
        if (!preg_match('/opt-in|FILE_UPLOAD_WEB_AOT_LINK_GATE=0/i', $matrixLine.$section)) {
            $errors[] = 'examples/README.md: 006 run matrix should mark AOT link opt-in when FILE_UPLOAD_WEB_AOT_LINK_GATE default is 0';
        }
    }

    if ('1' === $aotDefault) {
        if (!preg_match('/execute\s+default-on|#2012/i', $matrixLine)) {
            $errors[] = 'examples/README.md: 006 run matrix AOT column should say execute default-on (#2012) when FILE_UPLOAD_WEB_AOT_SMOKE_GATE default is 1';
        }
        if (preg_match('/execute\s+opt-in/i', $matrixLine)) {
            $errors[] = 'examples/README.md: 006 run matrix says execute opt-in but FILE_UPLOAD_WEB_AOT_SMOKE_GATE default is 1';
        }
    } else {
        if (!preg_match('/execute\s+opt-in|FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0/i', $matrixLine.($section ?? ''))) {
            $errors[] = 'examples/README.md: 006 run matrix should mark AOT execute opt-in when FILE_UPLOAD_WEB_AOT_SMOKE_GATE default is 0';
        }
    }
}

if (null !== $section && str_contains($section, 'move_uploaded_file')) {
    $capabilities = $root.'/docs/capabilities.md';
    if (!is_readable($capabilities)) {
        $errors[] = 'docs/capabilities.md: missing (required when examples/README.md mentions move_uploaded_file)';
    } else {
        $capBody = (string) file_get_contents($capabilities);
        if (!preg_match(
            '/\|\s*`move_uploaded_file`\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
            $capBody
        )) {
            $errors[] = 'docs/capabilities.md: `move_uploaded_file` must show VM yes, JIT yes, AOT yes (#2005)';
        }
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-rebuild-examples-006-row: {$err}\n");
    }
    fwrite(STDERR, "check-rebuild-examples-006-row: FAILED (sync examples/README.md with script/ci-defaults.env; see #2018)\n");
    exit(1);
}

fwrite(
    STDOUT,
    'check-rebuild-examples-006-row: OK (gates smoke='.$smokeDefault.' link='.$linkDefault.' aot='.$aotDefault
    .'; benchmark row '.($expectBenchRow ? 'expected' : 'omitted').")\n"
);
exit(0);

function should_expect_fileupload_benchmark_row(string $repoRoot): bool
{
    if ('1' === getenv('BENCH_FILEUPLOADWEB')) {
        return true;
    }
    if ('0' === getenv('FILEUPLOADWEB_LINT_GATE')) {
        return false;
    }

    return fileupload_web_lint_passes($repoRoot);
}

function fileupload_web_lint_passes(string $repoRoot): bool
{
    $phpc = $repoRoot.'/phpc';
    if (!is_executable($phpc)) {
        return false;
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [$phpc, 'lint', '--all', $repoRoot.'/examples/006-FileUploadWeb'],
        $descriptorSpec,
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        return false;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return 0 === proc_close($proc);
}

function benchmark_table_has_fileupload_web_row(string $readmeBody): bool
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return false;
    }

    return (bool) preg_match('/\|\s*006-FileUploadWeb\s*\|/i', $m[1]);
}

function extract_fileupload_web_benchmark_line(string $readmeBody): ?string
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return null;
    }
    if (!preg_match('/^.*\|\s*006-FileUploadWeb\s*\|.*$/mi', $m[1], $line)) {
        return null;
    }

    return trim($line[0]);
}

function fileupload_benchmark_row_aot_columns_honest(string $rowLine, string $repoRoot): bool
{
    $parts = array_map('trim', explode('|', $rowLine));
    $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
    if (count($parts) < 6) {
        return true;
    }
    $compileCol = $parts[4] ?? '';
    $compiledCol = $parts[5] ?? '';
    $compileNa = (bool) preg_match('/n\/a/i', $compileCol);
    $compiledNa = (bool) preg_match('/n\/a/i', $compiledCol);

    if (!llvm_ready_for_fileupload_check($repoRoot)) {
        return true;
    }

    if ('1' === getenv('BENCH_FILEUPLOADWEB_AOT')) {
        return !$compileNa && !$compiledNa;
    }

    if (fileupload_web_aot_execute_probe($repoRoot)) {
        return !$compileNa && !$compiledNa;
    }

    return $compileNa && $compiledNa;
}

function llvm_ready_for_fileupload_check(string $repoRoot): bool
{
    $candidates = [];
    $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
    if (false !== $fromEnv && '' !== $fromEnv) {
        $candidates[] = $fromEnv;
    }
    $candidates[] = $repoRoot.'/.llvm';
    $candidates[] = '/opt/llvm9';
    foreach ($candidates as $dir) {
        if (is_file($dir.'/libLLVM-9.so.1')) {
            return true;
        }
    }

    return false;
}

function fileupload_web_aot_execute_probe(string $repoRoot): bool
{
    if ('0' === getenv('FILE_UPLOAD_WEB_AOT_PROBE')) {
        return false;
    }
    if (!llvm_ready_for_fileupload_check($repoRoot)) {
        return false;
    }
    $phpc = $repoRoot.'/phpc';
    $project = $repoRoot.'/examples/006-FileUploadWeb';
    $binary = $project.'/.phpc/bin/app';
    if (!is_executable($phpc) || !is_file($project.'/example.php')) {
        return false;
    }

    $env = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    $llvmDir = null;
    foreach ([getenv('PHP_COMPILER_LLVM_PATH') ?: '', $repoRoot.'/.llvm', '/opt/llvm9'] as $dir) {
        if ('' !== $dir && is_file($dir.'/libLLVM-9.so.1')) {
            $llvmDir = realpath($dir) ?: $dir;
            break;
        }
    }
    if (null !== $llvmDir) {
        $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
    }

    if (!is_executable($binary)) {
        $build = proc_open(
            [$phpc, 'build', '--project', $project],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $env
        );
        if (!is_resource($build)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($build)) {
            return false;
        }
    }

    if (!is_executable($binary)) {
        return false;
    }

    foreach (fileupload_web_multipart_cgi_env() as $key => $value) {
        $env[$key] = $value;
    }

    $stdout = check_fileupload_run_binary($repoRoot, $binary, $env);
    if (null === $stdout) {
        return false;
    }

    return str_contains($stdout, 'Uploaded: README.md');
}

/**
 * @return array<string, string>
 */
function fileupload_web_multipart_cgi_env(): array
{
    return [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_BODY' => "--phpcFileB\r\n"
            ."Content-Disposition: form-data; name=\"doc\"; filename=\"README.md\"\r\n"
            ."Content-Type: text/plain\r\n\r\n"
            ."bytes\r\n"
            ."--phpcFileB--\r\n",
        'CONTENT_TYPE' => 'multipart/form-data; boundary=phpcFileB',
        'SCRIPT_NAME' => '/example.php',
        'REQUEST_URI' => '/example.php',
    ];
}

/**
 * @param array<string, string> $env
 */
function check_fileupload_run_binary(string $repoRoot, string $binary, array $env): ?string
{
    $proc = proc_open(
        [$binary],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot,
        $env
    );
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($proc)) {
        return null;
    }

    return false !== $stdout ? $stdout : '';
}

function ci_defaults_gate_default(string $repoRoot, string $gate): string
{
    $path = $repoRoot.'/script/ci-defaults.env';
    if (!is_readable($path)) {
        return '1';
    }
    $body = (string) file_get_contents($path);
    $pattern = '/export\s+'.preg_quote($gate, '/').'="\$\{'
        .preg_quote($gate, '/').':-([01])\}"/';
    if (preg_match($pattern, $body, $m)) {
        return $m[1];
    }

    return '1';
}

function extract_fileupload_run_matrix_line(string $readmeBody): ?string
{
    if (!preg_match('/^\| \[006-FileUploadWeb\].*$/mi', $readmeBody, $line)) {
        return null;
    }

    return trim($line[0]);
}

function extract_fileupload_section(string $readmeBody): ?string
{
    if (!preg_match(
        '/### 006-FileUploadWeb\s*\n(.*?)(?=\n### |\n## |\z)/ims',
        $readmeBody,
        $m
    )) {
        return null;
    }

    return $m[1];
}

/**
 * @param list<string> $errors
 * @param array{on: list<string>, off: list<string>} $needles
 */
function gate_doc_errors(
    array &$errors,
    string $label,
    string $gate,
    string $default,
    string $section,
    array $needles
): void {
    if (!str_contains($section, $gate)) {
        $errors[] = "examples/README.md: ### 006-FileUploadWeb must mention {$gate} ({$label})";

        return;
    }

    $pool = '1' === $default ? $needles['on'] : $needles['off'];
    foreach ($pool as $needle) {
        if (str_contains($section, $needle)) {
            return;
        }
    }

    $state = '1' === $default ? 'default-on' : 'opt-in';
    $errors[] = "examples/README.md: ### 006-FileUploadWeb {$label} docs out of sync with {$gate} default {$default} (expected {$state} wording; see #2018)";
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 006-FileUploadWeb run matrix vs ci-defaults.env gate defaults (issue #2018).
 *
 * Asserts VM multipart, AOT link, and AOT execute columns track:
 *   FILE_UPLOAD_WEB_SMOKE_GATE, FILE_UPLOAD_WEB_AOT_LINK_GATE, FILE_UPLOAD_WEB_AOT_SMOKE_GATE
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

fwrite(STDOUT, "check-rebuild-examples-006-row: OK (gates smoke={$smokeDefault} link={$linkDefault} aot={$aotDefault})\n");
exit(0);

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

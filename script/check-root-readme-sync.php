#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard root README.md against stale MiniWebApp north-star wording (issue #1832).
 *
 * Fails when known post-#764 blocker phrases remain while examples/README.md
 * documents native execute as green. Also guards 005-SessionsWeb,
 * 006-FileUploadWeb, 007-ThrowsWeb, and 008-SelfHostProbe shipped-example rows
 * (#1924, #2017, #2094, #2229). Enable in CI via ROOT_README_SYNC_GATE=1
 * (default in ci-defaults.env after #1525). Stricter 006/007/008 stale-phrase
 * checks: ROOT_README_006_SYNC_GATE / ROOT_README_007_SYNC_GATE /
 * ROOT_README_008_SYNC_GATE (006/007 default on; 008 opt-in). Opt out:
 * ROOT_README_SYNC_GATE=0.
 *
 * Usage:
 *   php script/check-root-readme-sync.php
 */

$root = dirname(__DIR__);
$readme = $root.'/README.md';
$examplesReadme = $root.'/examples/README.md';

if (!is_readable($readme)) {
    fwrite(STDERR, "check-root-readme-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$lines = preg_split("/\r\n|\n|\r/", $body) ?: [];

/** Phrases that imply #764 native execute is still open (issue #1832). */
$stale = [
    'empty stdout until [#764]',
    'empty stdout until #764',
    'native execute blocked',
    'execute blocked #764',
    'blocked #764',
    'native execute partial',
    'execute 🚧',
];

$errors = [];
foreach ($stale as $phrase) {
    if (!str_contains($body, $phrase)) {
        continue;
    }
    foreach ($lines as $num => $line) {
        if (!str_contains($line, $phrase)) {
            continue;
        }
        $lineNo = $num + 1;
        $errors[] = "stale phrase in README.md:{$lineNo}: {$phrase}";
    }
}

if (preg_match('/003[^\n]{0,80}AOT execute[^\n]*🚧/u', $body)
    || preg_match('/\*\*003\*\*[^\n]{0,40}execute[^\n]*🚧/u', $body)) {
    $errors[] = 'README.md: 003 AOT execute should not be 🚧 partial (post-#764; sync #1525)';
}

if (is_readable($examplesReadme)) {
    $examples = (string) file_get_contents($examplesReadme);
    if (str_contains($examples, 'native execute ✅') && !str_contains($body, 'native execute ✅')) {
        $errors[] = 'README.md: out of sync with examples/README.md (003 native execute status)';
    }
    if (str_contains($examples, '005-SessionsWeb') && !str_contains($body, '005-SessionsWeb')) {
        $errors[] = 'README.md: missing 005-SessionsWeb row (sync examples/README.md; #1924)';
    }
    if (str_contains($examples, '006-FileUploadWeb') && !str_contains($body, '006-FileUploadWeb')) {
        $errors[] = 'README.md: missing 006-FileUploadWeb row (sync examples/README.md; #2017)';
    }
    if (str_contains($examples, '007-ThrowsWeb') && !str_contains($body, '007-ThrowsWeb')) {
        $errors[] = 'README.md: missing 007-ThrowsWeb row (sync examples/README.md; #2094)';
    }
    if (str_contains($examples, '008-SelfHostProbe') && !str_contains($body, '008-SelfHostProbe')) {
        $errors[] = 'README.md: missing 008-SelfHostProbe row (sync examples/README.md; #2229)';
    }
    if (str_contains($examples, '009-FastCGIWeb') && !str_contains($body, '009-FastCGIWeb')) {
        $errors[] = 'README.md: missing 009-FastCGIWeb row (sync examples/README.md; #2353)';
    }
    if (str_contains($examples, '| [006-FileUploadWeb]')
        && preg_match('/\| \[006-FileUploadWeb\][^\n]*✅/u', $examples)
        && preg_match('/\| \[006-FileUploadWeb\][^\n]*🚧/u', $body)) {
        $errors[] = 'README.md: 006-FileUploadWeb row shows 🚧 but examples/README.md is ✅ (#2017)';
    }
    if (str_contains($examples, '| [007-ThrowsWeb]')
        && preg_match('/\| \[007-ThrowsWeb\][^\n]*✅/u', $examples)
        && preg_match('/\| \[007-ThrowsWeb\][^\n]*🚧/u', $body)) {
        $errors[] = 'README.md: 007-ThrowsWeb row shows 🚧 but examples/README.md is ✅ (#2094)';
    }
    if (str_contains($examples, '| [008-SelfHostProbe]')
        && preg_match('/\| \[008-SelfHostProbe\][^\n]*✅/u', $examples)
        && preg_match('/\| \[008-SelfHostProbe\][^\n]*🚧/u', $body)) {
        $errors[] = 'README.md: 008-SelfHostProbe row shows 🚧 but examples/README.md is ✅ (#2229)';
    }
}

$check006Stale = (getenv('ROOT_README_006_SYNC_GATE') ?: '0') === '1';
if ($check006Stale) {
    $smokeDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_SMOKE_GATE');
    $linkDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_LINK_GATE');
    $aotDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE');
    $stale006 = [];
    if ('1' === $smokeDefault) {
        $stale006[] = 'FILE_UPLOAD_WEB_SMOKE_GATE=0';
        $stale006[] = '006 multipart smoke opt-in';
    }
    if ('1' === $linkDefault) {
        $stale006[] = 'FILE_UPLOAD_WEB_AOT_LINK_GATE=0';
        $stale006[] = '006 AOT link opt-in only';
    }
    if ('1' === $aotDefault) {
        $stale006[] = 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0';
        $stale006[] = '+ 006 when gate on';
    }
    foreach ($stale006 as $phrase) {
        if (!str_contains($body, $phrase)) {
            continue;
        }
        foreach ($lines as $num => $line) {
            if (!str_contains($line, $phrase)) {
                continue;
            }
            if (!preg_match('/006|FileUpload|FILE_UPLOAD/i', $line)) {
                continue;
            }
            $lineNo = $num + 1;
            $errors[] = "stale 006 phrase in README.md:{$lineNo}: {$phrase} (gate default-on; #2017)";
        }
    }
}

$check007Stale = (getenv('ROOT_README_007_SYNC_GATE') ?: '0') === '1';
if ($check007Stale) {
    $throwsSmokeDefault = ci_defaults_gate_default($root, 'THROWS_WEB_SMOKE_GATE');
    $stale007 = [];
    if ('1' === $throwsSmokeDefault) {
        $stale007[] = 'THROWS_WEB_SMOKE_GATE=0';
        $stale007[] = '007 throw/catch smoke opt-in';
    }
    foreach ($stale007 as $phrase) {
        if (!str_contains($body, $phrase)) {
            continue;
        }
        foreach ($lines as $num => $line) {
            if (!str_contains($line, $phrase)) {
                continue;
            }
            if (!preg_match('/007|ThrowsWeb|THROWS_WEB/i', $line)) {
                continue;
            }
            $lineNo = $num + 1;
            $errors[] = "stale 007 phrase in README.md:{$lineNo}: {$phrase} (gate default-on; #2094)";
        }
    }
}

$check008Stale = (getenv('ROOT_README_008_SYNC_GATE') ?: '0') === '1';
if ($check008Stale) {
    $selfhostSmokeDefault = ci_defaults_gate_default($root, 'EXAMPLES_SELFHOSTPROBE_SMOKE_GATE');
    $selfhostAotDefault = ci_defaults_gate_default($root, 'SELFHOSTPROBE_AOT_SMOKE_GATE');
    $stale008 = [];
    if ('1' === $selfhostSmokeDefault) {
        $stale008[] = 'EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=0';
        $stale008[] = '008-SelfHostProbe smoke opt-in';
    }
    if ('1' === $selfhostAotDefault) {
        $stale008[] = 'SELFHOSTPROBE_AOT_SMOKE_GATE=0';
        $stale008[] = '008 AOT opt-in';
    }
    foreach ($stale008 as $phrase) {
        if (!str_contains($body, $phrase)) {
            continue;
        }
        foreach ($lines as $num => $line) {
            if (!str_contains($line, $phrase)) {
                continue;
            }
            if (!preg_match('/008|SelfHostProbe|SELFHOSTPROBE|EXAMPLES_SELFHOSTPROBE/i', $line)) {
                continue;
            }
            $lineNo = $num + 1;
            $errors[] = "stale 008 phrase in README.md:{$lineNo}: {$phrase} (gate default-on; #2229)";
        }
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-root-readme-sync: {$err}\n");
    }
    fwrite(STDERR, "check-root-readme-sync: FAILED (fix README.md; see #48, #1525, #1832, #2017, #2094, #2229)\n");
    exit(1);
}

fwrite(STDOUT, "check-root-readme-sync: OK\n");
exit(0);

function ci_defaults_gate_default(string $repoRoot, string $gate): string
{
    $path = $repoRoot.'/script/ci-defaults.env';
    if (!is_readable($path)) {
        return '1';
    }
    $envBody = (string) file_get_contents($path);
    $pattern = '/export\s+'.preg_quote($gate, '/').'="\$\{'
        .preg_quote($gate, '/').':-([01])\}"/';
    if (preg_match($pattern, $envBody, $m)) {
        return $m[1];
    }

    return '1';
}

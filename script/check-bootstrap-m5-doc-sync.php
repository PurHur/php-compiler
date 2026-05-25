#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/bootstrap-m5-fast-path.md against m3-allowlist-snapshot.txt drift (#1984).
 *
 * Doc follows machine-readable SSOT (mirror #1872 spine-count policy).
 *
 * Usage:
 *   php script/check-bootstrap-m5-doc-sync.php
 */

require_once __DIR__.'/bootstrap-m5-doc-sync.php';

$root = dirname(__DIR__);
$docPath = $root.'/docs/bootstrap-m5-fast-path.md';
$snapshotPath = $root.'/script/m3-allowlist-snapshot.txt';

$fromDoc = bootstrap_m5_doc_parse_allow_deny($docPath);
$fromSnapshot = bootstrap_m3_allowlist_read_snapshot($snapshotPath);

$errors = [];

if (!is_readable($docPath)) {
    $errors[] = 'missing docs/bootstrap-m5-fast-path.md';
}
if (!is_readable($snapshotPath)) {
    $errors[] = 'missing script/m3-allowlist-snapshot.txt';
}

if ([] === $errors) {
    // Deny wins in JIT (isM3CompileDriverSpineDenyName before allowlist); same for doc sync.
    $snapAllow = array_values(array_diff($fromSnapshot['allow'], $fromSnapshot['deny']));
    $snapDeny = $fromSnapshot['deny'];
    foreach (['allow' => $snapAllow, 'deny' => $snapDeny] as $section => $snapList) {
        $docList = $fromDoc[$section];
        $missingInDoc = array_values(array_diff($snapList, $docList));
        $staleInDoc = array_values(array_diff($docList, $snapList));
        if ([] !== $missingInDoc) {
            $errors[] = "{$section}: doc missing snapshot entries: ".implode(', ', $missingInDoc);
        }
        if ([] !== $staleInDoc) {
            $errors[] = "{$section}: doc stale vs snapshot: ".implode(', ', $staleInDoc);
        }
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-m5-doc-sync: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-m5-doc-sync: FAILED — update docs/bootstrap-m5-fast-path.md Step 2 / deny table with script/m3-allowlist-snapshot.txt (#1984).\n");
    exit(1);
}

fwrite(STDOUT, 'check-bootstrap-m5-doc-sync: OK (doc allow '.count($fromDoc['allow']).', deny '.count($fromDoc['deny'])." vs snapshot)\n");
exit(0);

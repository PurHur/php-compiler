#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M4 gen-2 emit_path docs against bootstrap-loop script drift (issue #2115).
 *
 * Usage:
 *   php script/check-selfhost-m4-gen2-sync.php
 */

require_once __DIR__.'/bootstrap-m4-gen2-sync-lib.php';

$root = dirname(__DIR__);
$gen1Link = $root.'/script/bootstrap-loop-gen1-link.sh';
$probe = $root.'/script/bootstrap-loop-probe.sh';

$errors = [];

foreach ([$gen1Link => 'bootstrap-loop-gen1-link.sh', $probe => 'bootstrap-loop-probe.sh'] as $path => $label) {
    if (!is_readable($path)) {
        $errors[] = "missing script: {$label}";
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-m4-gen2-sync: {$err}\n");
    }
    exit(1);
}

$gen1Source = (string) file_get_contents($gen1Link);
$probeSource = (string) file_get_contents($probe);
$profile = bootstrap_m4_gen2_script_profile($gen1Source, $probeSource);

if (!$profile['zend_fallback'] && !$profile['native_success']) {
    $errors[] = 'bootstrap-loop-gen1-link.sh: expected Zend fallback or native OK emit_path lines';
}

$trackedDocs = [
    'docs/self-host-target.md',
    'docs/bootstrap-selfhost.md',
];

foreach ($trackedDocs as $rel) {
    $path = $root.'/'.$rel;
    if (!is_readable($path)) {
        $errors[] = "missing doc: {$rel}";
        continue;
    }
    bootstrap_m4_gen2_validate_doc($rel, (string) file_get_contents($path), $profile, $errors);
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-m4-gen2-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-m4-gen2-sync: FAILED — sync M4 gen-2 docs with bootstrap-loop-gen1-link.sh (#2115).\n");
    exit(1);
}

$mode = $profile['native_success'] && !$profile['zend_fallback'] ? 'native-only' : ($profile['zend_fallback'] ? 'zend-partial' : 'unknown');
fwrite(STDOUT, "check-selfhost-m4-gen2-sync: OK (M4 gen-2 profile {$mode}; BOOTSTRAP_M4_GEN2_STRICT=".($profile['gen2_strict_env'] ? 'yes' : 'no').")\n");
exit(0);

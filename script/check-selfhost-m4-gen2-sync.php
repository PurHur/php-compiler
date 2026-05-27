#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M4 gen-2/gen-3 emit_path docs against bootstrap-loop script drift (issue #2115).
 *
 * Usage:
 *   php script/check-selfhost-m4-gen2-sync.php
 */

require_once __DIR__.'/bootstrap-m4-gen2-sync-lib.php';

$root = dirname(__DIR__);
$gen1Link = $root.'/script/bootstrap-loop-gen1-link.sh';
$gen2Recompile = $root.'/script/bootstrap-loop-gen2-recompile-spine.sh';
$probe = $root.'/script/bootstrap-loop-probe.sh';

$errors = [];

foreach ([$gen1Link => 'bootstrap-loop-gen1-link.sh', $gen2Recompile => 'bootstrap-loop-gen2-recompile-spine.sh', $probe => 'bootstrap-loop-probe.sh'] as $path => $label) {
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
$gen2RecompileSource = (string) file_get_contents($gen2Recompile);
$profile = bootstrap_m4_gen2_script_profile($gen1Source, $probeSource);
$gen3Profile = bootstrap_m4_gen3_script_profile($gen2RecompileSource);

if (!$profile['zend_fallback'] && !$profile['native_success']) {
    $errors[] = 'bootstrap-loop-gen1-link.sh: expected Zend fallback or native OK emit_path lines';
}

$trackedDocs = [
    'docs/self-host-target.md',
    'docs/bootstrap-selfhost.md',
    'docs/bootstrap-generations.md',
];

foreach ($trackedDocs as $rel) {
    $path = $root.'/'.$rel;
    if (!is_readable($path)) {
        $errors[] = "missing doc: {$rel}";
        continue;
    }
    bootstrap_m4_gen2_validate_doc($rel, (string) file_get_contents($path), $profile, $gen3Profile, $errors);
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-m4-gen2-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-m4-gen2-sync: FAILED — sync M4 gen-2/gen-3 docs with bootstrap-loop scripts (#2115).\n");
    exit(1);
}

$mode = $profile['native_success'] && !$profile['zend_fallback'] ? 'native-only' : ($profile['zend_fallback'] ? 'zend-partial' : 'unknown');
$gen3 = $gen3Profile['gen3_spine_script'] ? 'gen-3-spine-wired' : 'no-gen-3';
fwrite(STDOUT, "check-selfhost-m4-gen2-sync: OK (M4 gen-2 profile {$mode}, {$gen3}; BOOTSTRAP_M4_GEN2_STRICT=".($profile['gen2_strict_env'] ? 'yes' : 'no').")\n");
exit(0);

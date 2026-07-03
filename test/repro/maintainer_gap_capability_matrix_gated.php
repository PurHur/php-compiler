<?php

declare(strict_types=1);

/**
 * Maintainer repro: capability matrix VM column must match function_exists() (#11904).
 */

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/script/capability-matrix.php';

$capabilities = applyBuiltinCapabilityCurations(
    applyBuiltinAdvertisementParity(collectCapabilities($root), $root)
);

$runtime = new PHPCompiler\Runtime();
$ctx = $runtime->vmContext;
$drift = [];

foreach ($capabilities as $name => $row) {
    $exists = isset($ctx->functions[$name]);
    if ($row['vm'] !== $exists) {
        $drift[] = sprintf(
            '%s: matrix vm=%s function_exists=%s',
            $name,
            $row['vm'] ? 'yes' : 'no',
            $exists ? 'true' : 'false'
        );
    }
}

if ($drift !== []) {
    fwrite(STDERR, "Capability matrix / function_exists drift:\n".implode("\n", $drift)."\n");
    exit(1);
}

foreach (['fmin', 'fmax', 'fpow', 'str_increment', 'str_decrement', 'class_has_method'] as $fn) {
    if (!isset($capabilities[$fn])) {
        continue;
    }
    if ($capabilities[$fn]['vm'] && !isset($ctx->functions[$fn])) {
        fwrite(STDERR, $fn.": matrix VM=yes but function_exists() false\n");
        exit(1);
    }
}

fwrite(STDOUT, 'capability matrix gated parity OK ('.count($capabilities)." builtins)\n");

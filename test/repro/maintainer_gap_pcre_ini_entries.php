<?php

declare(strict_types=1);

/**
 * Issue #12433 — pcre.jit / pcre.recursion_limit INI entries (ext/pcre/php_pcre.c).
 */
$jit = ini_get('pcre.jit');
$recursion = ini_get('pcre.recursion_limit');

if ('1' !== $jit) {
    fwrite(STDERR, "ini_get(pcre.jit)={$jit}\n");
    exit(1);
}
if ('100000' !== $recursion) {
    fwrite(STDERR, "ini_get(pcre.recursion_limit)={$recursion}\n");
    exit(1);
}

$all = ini_get_all();
if (!isset($all['pcre.jit']) || !isset($all['pcre.recursion_limit'])) {
    fwrite(STDERR, "missing from ini_get_all\n");
    exit(1);
}

$oldJit = ini_set('pcre.jit', '0');
if ('1' !== $oldJit || '' !== ini_get('pcre.jit')) {
    fwrite(STDERR, "ini_set(pcre.jit) old={$oldJit} get=".var_export(ini_get('pcre.jit'), true)."\n");
    exit(1);
}
ini_restore('pcre.jit');
if ('1' !== ini_get('pcre.jit')) {
    fwrite(STDERR, "ini_restore(pcre.jit) get=".ini_get('pcre.jit')."\n");
    exit(1);
}

$oldRec = ini_set('pcre.recursion_limit', '50000');
if ('100000' !== $oldRec || '50000' !== ini_get('pcre.recursion_limit')) {
    fwrite(STDERR, "ini_set(pcre.recursion_limit) old={$oldRec} get=".ini_get('pcre.recursion_limit')."\n");
    exit(1);
}
ini_restore('pcre.recursion_limit');
if ('100000' !== ini_get('pcre.recursion_limit')) {
    fwrite(STDERR, "ini_restore(pcre.recursion_limit) get=".ini_get('pcre.recursion_limit')."\n");
    exit(1);
}

echo "jit={$jit} recursion={$recursion}\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Grammar-based PHP program generator for Zend-vs-VM/AOT differential fuzz (#36398).
 *
 * Usage:
 *   php script/fuzz/gen.php --seed 42
 *   php script/fuzz/gen.php --seed 42 --out /tmp/p.php
 *   php script/fuzz/gen.php --seed 42 --shape string_concat_loop
 *
 * Programs are deterministic for a given seed+shape, print only via echo/var_dump/printf,
 * and avoid clocks/randomness/network/filesystem writes.
 */

require __DIR__.'/lib.php';
require __DIR__.'/generate.php';

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    $opts = getopt('', ['seed:', 'out:', 'shape:', 'help']);
    if (isset($opts['help']) || !isset($opts['seed'])) {
        fwrite(STDERR, "Usage: php script/fuzz/gen.php --seed N [--shape NAME] [--out FILE]\n");
        fwrite(STDERR, "Shapes: auto, arith_main, arith_fn, string_concat_loop, array_list, control_break, mixed_scope\n");
        exit(isset($opts['help']) ? 0 : 2);
    }

    $seed = (int) $opts['seed'];
    $shape = isset($opts['shape']) ? (string) $opts['shape'] : 'auto';
    $out = isset($opts['out']) ? (string) $opts['out'] : null;

    $src = fuzz_generate_program($seed, $shape);
    if ($out !== null) {
        if (file_put_contents($out, $src) === false) {
            fwrite(STDERR, "fuzz/gen: cannot write {$out}\n");
            exit(1);
        }
        fwrite(STDOUT, $out."\n");
    } else {
        fwrite(STDOUT, $src);
    }
}

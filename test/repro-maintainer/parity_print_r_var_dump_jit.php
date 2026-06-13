<?php

/**
 * Issue #4010 repro: print_r()/var_dump() JIT/AOT lowering vs VM.
 *
 * ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh
 * php test/repro-maintainer/parity_print_r_var_dump_jit.php
 * php bin/vm.php test/repro-maintainer/parity_print_r_var_dump_jit.php
 * php bin/jit.php test/repro-maintainer/parity_print_r_var_dump_jit.php
 * '
 */
$a = ['k' => 1, 'nested' => ['x' => 2]];
$r = print_r($a, true);
var_dump(str_contains($r, 'k'));
var_dump($a);

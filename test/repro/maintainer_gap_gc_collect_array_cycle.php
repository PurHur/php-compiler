<?php

declare(strict_types=1);

/**
 * Maintainer repro: gc_collect_cycles() VM/JIT parity on array reference cycles (#13403, #13882).
 *
 * Zend: php test/compliance/cases/stdlib/gc_collect_array_cycle.phpt
 * VM:   php bin/vm.php test/repro/maintainer_gap_gc_collect_array_cycle.php
 * JIT:  php bin/jit.php test/repro/maintainer_gap_gc_collect_array_cycle.php
 */
$a = [];
$a[0] = &$a;
unset($a);
echo gc_collect_cycles(), "\n";

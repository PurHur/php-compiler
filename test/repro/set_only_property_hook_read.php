<?php
/**
 * Repro #22452 / re-#19163 — set-only hook read returns backing (php-src-strict).
 * php-src: Zend/zend_object_handlers.c
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/set_only_property_hook_read.php
 */
class Counter {
    public int $x {
        set(int $v) => $this->x = max(0, $v);
    }
}
$c = new Counter();
$c->x = -1;
echo $c->x, "\n";

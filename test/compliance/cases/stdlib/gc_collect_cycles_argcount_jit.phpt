--TEST--
stdlib JIT gc_collect_cycles()/gc_disable() extra arguments ArgumentCountError (#17195, ext/standard/php_gc.c)
--FILE--
<?php
declare(strict_types=1);

function check_gc_argcount(): void
{
    try {
        gc_collect_cycles(1);
        echo "gc_collect_cycles uncaught\n";
    } catch (ArgumentCountError $e) {
        echo 'gc_collect_cycles: ', $e->getMessage(), "\n";
    }

    try {
        gc_disable(1);
        echo "gc_disable uncaught\n";
    } catch (ArgumentCountError $e) {
        echo 'gc_disable: ', $e->getMessage(), "\n";
    }

    echo "ok\n";
}

check_gc_argcount();
--EXPECT--
gc_collect_cycles: gc_collect_cycles() expects exactly 0 arguments, 1 given
gc_disable: gc_disable() expects exactly 0 arguments, 1 given
ok

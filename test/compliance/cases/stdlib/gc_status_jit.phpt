--TEST--
stdlib gc_status()/gc_mem_caches() JIT lowering (#3280, #5109)
--FILE--
<?php
foreach (['gc_status', 'gc_mem_caches'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$st = gc_status();
gc_mem_caches();
echo 'runs=', $st['runs'], "\n";
echo 'threshold=', $st['threshold'], "\n";
echo 'roots=', $st['roots'], "\n";
--EXPECT--
gc_status=yes
gc_mem_caches=yes
runs=0
threshold=10001
roots=0

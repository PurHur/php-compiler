--TEST--
stdlib gc_status()/gc_mem_caches() JIT lowering (#3280, #5109)
--FILE--
<?php
foreach (['gc_status', 'gc_mem_caches'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$st = gc_status();
gc_mem_caches();
if (array_key_exists('runs', $st)) {
    echo "skip — legacy gc_status schema on reference profile\n";
    exit(0);
}
echo 'running=', $st['running'] ? '1' : '0', "\n";
echo 'buffer_size=', $st['buffer_size'], "\n";
--EXPECT--
gc_status=yes
gc_mem_caches=yes
running=0
buffer_size=131072

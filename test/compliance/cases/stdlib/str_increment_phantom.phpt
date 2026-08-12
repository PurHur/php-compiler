--TEST--
stdlib str_increment()/str_decrement() — not advertised or callable on PHP 8.2 reference profile (#14709, #30511)
--FILE--
<?php
echo function_exists('str_increment') ? "si_fail\n" : "si_ok\n";
echo function_exists('str_decrement') ? "sd_fail\n" : "sd_ok\n";
try {
    str_increment('a');
    echo "si_call_fail\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    str_decrement('b');
    echo "sd_call_fail\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
si_ok
sd_ok
Error: Call to undefined function str_increment()
Error: Call to undefined function str_decrement()

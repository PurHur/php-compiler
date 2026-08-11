--TEST--
Language: array unpack Error/TypeError appends ", <type> given" on PROFILE≥8.4 (zend_vm_def.h, #30055)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([1, 1.5, 'ab', true, false, null, new stdClass] as $v) {
    try {
        $b = [...$v];
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo "after\n";
?>
--EXPECT--
Error:Only arrays and Traversables can be unpacked, int given
Error:Only arrays and Traversables can be unpacked, float given
Error:Only arrays and Traversables can be unpacked, string given
Error:Only arrays and Traversables can be unpacked, true given
Error:Only arrays and Traversables can be unpacked, false given
Error:Only arrays and Traversables can be unpacked, null given
TypeError:Only arrays and Traversables can be unpacked, stdClass given
after

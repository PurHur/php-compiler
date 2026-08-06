--TEST--
language: array literal spread of runtime non-traversable is catchable Error (zend_vm_def.h, #27952)
--FILE--
<?php
foreach ([123, 'ab', 1.5, true, null] as $a) {
    try {
        $b = [...$a];
        echo "ok\n";
    } catch (Throwable $e) {
        echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    $a = new stdClass();
    $b = [...$a];
    echo "ok\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
?>
--EXPECT--
caught:Error:Only arrays and Traversables can be unpacked
caught:Error:Only arrays and Traversables can be unpacked
caught:Error:Only arrays and Traversables can be unpacked
caught:Error:Only arrays and Traversables can be unpacked
caught:Error:Only arrays and Traversables can be unpacked
caught:TypeError:Only arrays and Traversables can be unpacked
after

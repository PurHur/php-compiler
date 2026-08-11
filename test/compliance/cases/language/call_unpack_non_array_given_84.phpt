--TEST--
Language: call unpack TypeError appends ", <type> given" on PROFILE≥8.4 (zend_vm_def.h, #30023)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function f(...$a) {}
foreach ([1, 1.5, 'ab', true, false, null, new stdClass] as $v) {
    try {
        f(...$v);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo "after\n";
?>
--EXPECT--
TypeError:Only arrays and Traversables can be unpacked, int given
TypeError:Only arrays and Traversables can be unpacked, float given
TypeError:Only arrays and Traversables can be unpacked, string given
TypeError:Only arrays and Traversables can be unpacked, true given
TypeError:Only arrays and Traversables can be unpacked, false given
TypeError:Only arrays and Traversables can be unpacked, null given
TypeError:Only arrays and Traversables can be unpacked, stdClass given
after

--TEST--
stdlib ob_implicit_flush() — int rejected under declare(strict_types=1) (#12823, ext/standard/output.c)
--FILE--
<?php
declare(strict_types=1);
try {
    ob_implicit_flush(1);
    echo "fail\n";
} catch (TypeError $e) {
    echo "ok\n";
}
?>
--EXPECT--
ok

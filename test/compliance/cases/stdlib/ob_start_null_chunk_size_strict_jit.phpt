--TEST--
stdlib ob_start(null, null) JIT TypeError on $chunk_size under strict_types (#31228, ext/standard/output.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    $r = ob_start(null, null);
    if ($r) {
        ob_end_clean();
    }
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ob_start(): Argument #2 ($chunk_size) must be of type int, null given

--TEST--
stdlib touch() — null $filename soft-coerces on 8.4 JIT (#20362 supersedes #18245, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $r = @touch(null);
    echo var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false

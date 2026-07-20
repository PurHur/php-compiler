--TEST--
AOT: addslashes(null) TypeError on 8.4 forward profile (#21351)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    addslashes(null);
    echo "bad\n";
} catch (TypeError $e) {
    echo "ok\n";
}
--EXPECT--
ok

--TEST--
AOT: nl2br(null) TypeError on 8.4 forward profile (#21351)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    nl2br(null);
    echo "bad\n";
} catch (TypeError $e) {
    echo "ok\n";
}
--EXPECT--
ok

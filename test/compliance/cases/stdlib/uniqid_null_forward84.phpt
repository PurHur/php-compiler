--TEST--
stdlib uniqid(null) TypeError on 8.4 (#20138, re-#18788)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(uniqid(null));
    echo " COERCE\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError

--TEST--
stdlib interface_exists()/trait_exists()/enum_exists()/class_exists(null) TypeError on 8.4 (#19223)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['interface_exists', 'trait_exists', 'enum_exists', 'class_exists'] as $fn) {
    try {
        $fn(null);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
--EXPECT--
ok interface_exists
ok trait_exists
ok enum_exists
ok class_exists

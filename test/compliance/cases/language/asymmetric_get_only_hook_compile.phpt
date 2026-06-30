--TEST--
Language: private(set) property with get-only hook compiles (#13983, Zend/zend_property_hooks.c)
--FILE--
<?php
class C {
    private(set) string $x {
        get => 'hi';
    }
}
echo "ok\n";
--EXPECT--
ok

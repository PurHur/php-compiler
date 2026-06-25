--TEST--
Language: public private(set) compiles and enforces set visibility (#11546, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "compiled\n";
--EXPECT--
compiled

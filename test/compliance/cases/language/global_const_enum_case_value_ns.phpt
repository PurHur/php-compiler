--TEST--
Language: namespaced file-scope const with EnumCase->value/->name (#19567, zend_compile.c)
--FILE--
<?php
namespace N {
    enum E: string { case B = 'ok'; }
    const Y = E::B->value;
    const Z = \N\E::B->name;
    echo Y, "\n";
    echo Z, "\n";
}
--EXPECT--
ok
B

--TEST--
Language: enum const then case same name — compile fatal (#26557, zend_compile.c)
--FILE--
<?php
enum E {
    const Foo = 1;
    case Foo;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot redefine class constant E::Foo in %s on line %d

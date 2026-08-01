--TEST--
Language: enum case then const same name — compile fatal (#26557, zend_compile.c)
--FILE--
<?php
enum E {
    case Foo;
    const Foo = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot redefine class constant E::Foo in %s on line %d

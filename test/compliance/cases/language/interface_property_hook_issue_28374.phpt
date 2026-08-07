--TEST--
Language: interface hooked string $name — Good OK, BadI omission compile fatal (#28374, zend_inheritance.c)
--FILE--
<?php
interface I {
    public string $name { get; set; }
}
class Good implements I {
    public string $name = "g";
}
echo (new Good())->name, "\n";
class BadI implements I {}
echo "BadI ok\n";
new BadI();
--EXPECTF--
PHP Fatal error:  Class BadI must implement 1 interface property (I::$name { get; set; }) in %s on line %d
--EXPECT_EXIT--
255

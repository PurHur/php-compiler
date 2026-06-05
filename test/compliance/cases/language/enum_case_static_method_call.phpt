--TEST--
Language: enum case static method call (E::A)::label() (#6408, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    public static function label(): string { return 'ok'; }
}
echo (E::A)::label(), "\n";
--EXPECT--
ok

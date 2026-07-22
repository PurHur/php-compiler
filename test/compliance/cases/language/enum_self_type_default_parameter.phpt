--TEST--
Language: enum method/static parameter default self $e = self::CASE (zend_compile.c, #22085)
--FILE--
<?php
enum E {
    case A;
    case B;
    public function other(self $e = self::A): string {
        return $e->name;
    }
    public static function make(self $e = self::B): string {
        return $e->name;
    }
}
echo E::A->other(), "|", E::make(), "\n";
--EXPECT--
A|B

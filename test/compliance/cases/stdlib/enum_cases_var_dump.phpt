--TEST--
stdlib var_dump() on Enum::cases() — backed + unit cases show enum(Class::Case) (#4739, Zend/zend.c)
--FILE--
<?php
declare(strict_types=1);

enum Color: string { case Red = 'red'; case Green = 'green'; }
enum Size { case S; case M; }

ob_start();
var_dump(Color::cases());
var_dump(Size::cases());
echo Color::Red->name, "\n";
echo ob_get_clean();
--EXPECT--
array(2) {
  [0]=>
  enum(Color::Red)
  [1]=>
  enum(Color::Green)
}
array(2) {
  [0]=>
  enum(Size::S)
  [1]=>
  enum(Size::M)
}
Red

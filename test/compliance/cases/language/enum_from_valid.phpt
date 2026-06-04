--TEST--
Language: BackedEnum::from() valid int backing (#5533, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
echo E::from(1)->name;
echo E::from(1) === E::A ? 'same' : 'diff';
--EXPECT--
Asame

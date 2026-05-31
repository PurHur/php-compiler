--TEST--
Language: BackedEnum::from() (#3114)
--FILE--
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo Color::from('red')->name;
echo Color::from('blue')->value;
--EXPECT--
Redblue

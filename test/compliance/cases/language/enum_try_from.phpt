--TEST--
Language: BackedEnum::tryFrom() (#3114)
--FILE--
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo Color::tryFrom('red')->name;
echo Color::tryFrom('nope') === null ? 'null' : 'bad';
--EXPECT--
Rednull

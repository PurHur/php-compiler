--TEST--
AOT: BackedEnum::from() / tryFrom() (#4053, #3114)
--FILE--
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
try { Color::from('nope'); } catch (ValueError $e) { echo "ve\n"; }
echo Color::tryFrom('nope') === null ? "null\n" : "bad\n";
echo Color::from('red')->name, "\n";
--EXPECT--
ve
null
Red

--TEST--
Language: BackedEnum::from() on int-backed enum (#3114)
--FILE--
<?php
enum Level: int { case Low = 1; case High = 9; }
echo Level::from(9)->name;
echo Level::tryFrom(0) === null ? 'null' : 'bad';
--EXPECT--
Highnull

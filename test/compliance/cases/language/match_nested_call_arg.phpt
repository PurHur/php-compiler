--TEST--
match expression as nested call argument evaluates correctly (issue #9374)
--FILE--
<?php
declare(strict_types=1);
var_dump(strlen(match (1) { 1 => 'abc' }));
var_dump(match (1) { 1 => 'a', default => 'd' });
var_dump(count(match ([1]) { [1] => [1, 2] }));
--EXPECT--
int(3)
string(1) "a"
int(2)

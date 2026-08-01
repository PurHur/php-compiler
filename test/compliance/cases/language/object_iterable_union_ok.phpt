--TEST--
Language: object|iterable remains valid — Traversable only from iterable alias (#26563)
--FILE--
<?php
function f(object|iterable $x) {
    return is_object($x) ? 'o' : 'i';
}
echo f(new stdClass), "|", f([]), "\n";
--EXPECT--
o|i

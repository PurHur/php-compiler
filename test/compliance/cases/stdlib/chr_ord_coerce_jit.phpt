--TEST--
stdlib chr()/ord() JIT — numeric-string and TypeError parity (#5085)
--FILE--
<?php
echo chr(300), "\n";
echo chr('65'), "\n";
echo ord(''), "\n";
try {
    chr('abc');
} catch (TypeError $e) {
    echo 'chr TypeError', "\n";
    echo $e->getMessage(), "\n";
}
try {
    ord([]);
} catch (TypeError $e) {
    echo 'ord TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
,
A
0
chr TypeError
chr(): Argument #1 ($codepoint) must be of type int, string given
ord TypeError
ord(): Argument #1 ($character) must be of type string, array given

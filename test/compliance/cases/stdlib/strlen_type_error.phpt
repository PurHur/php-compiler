--TEST--
stdlib strlen()/substr()/strpos() — TypeError for array/object operands (#4622, ext/standard/string.c)
--FILE--
<?php
try {
    strlen([]);
    echo "no-ex\n";
} catch (TypeError $e) {
    echo "te_strlen\n";
    echo $e->getMessage(), "\n";
}
try {
    substr([], 0);
    echo "no-ex\n";
} catch (TypeError $e) {
    echo "te_substr\n";
    echo $e->getMessage(), "\n";
}
try {
    strpos([], 'x');
    echo "no-ex\n";
} catch (TypeError $e) {
    echo "te_strpos\n";
    echo $e->getMessage(), "\n";
}
try {
    strlen(new stdClass());
    echo "no-ex\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo strlen('abc'), "\n";
echo strlen(42), "\n";
echo substr('abcdef', 1, 2), "\n";
echo strpos('abcdef', 'cd'), "\n";
--EXPECT--
te_strlen
strlen(): Argument #1 ($string) must be of type string, array given
te_substr
substr(): Argument #1 ($string) must be of type string, array given
te_strpos
strpos(): Argument #1 ($haystack) must be of type string, array given
strlen(): Argument #1 ($string) must be of type string, stdClass given
3
2
bc
2

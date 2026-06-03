--TEST--
stdlib ucwords() — TypeError for non-string operands (#4950, ext/standard/string.c)
--FILE--
<?php
foreach ([[], new stdClass()] as $bad) {
    try {
        ucwords($bad);
        echo "no throw\n";
    } catch (Throwable $e) {
        echo $e::class, ': ', $e->getMessage(), "\n";
    }
}
try {
    $unused = ucwords('hello', []);
    echo "no throw\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo ucwords('hello world'), "\n";
--EXPECT--
TypeError: ucwords(): Argument #1 ($string) must be of type string, array given
TypeError: ucwords(): Argument #1 ($string) must be of type string, stdClass given
TypeError: ucwords(): Argument #2 ($separators) must be of type string, array given
Hello World

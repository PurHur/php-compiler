--TEST--
stdlib uniqid() prefix coercion and TypeError parity (#4544, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$id = uniqid(42, false);
echo str_starts_with($id, '42') ? "prefix\n" : "bad\n";

$e = uniqid('', 1);
echo strlen($e) > 21 ? "entropy\n" : "bad\n";

try {
    uniqid([], false);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
prefix
entropy
uniqid(): Argument #1 ($prefix) must be of type string, array given

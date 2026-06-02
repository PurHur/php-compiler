--TEST--
match strict === arm selection + UnhandledMatchError (issue #4371; zend_compile.c / zend_execute.c)
--FILE--
<?php

function f($x): string {
    return match ($x) {
        1 => 'one',
        "1" => 'string-one',
        default => 'default',
    };
}

echo f(1), "\n";
echo f("1"), "\n";
echo f(2), "\n";

try {
    match (true) {
        false => 'never',
    };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), "\n";
    echo str_contains($e->getMessage(), 'Unhandled match case') ? "1\n" : "0\n";
}
--EXPECT--
one
string-one
default
UnhandledMatchError
1

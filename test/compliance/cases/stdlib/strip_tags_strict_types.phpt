--TEST--
Stdlib: strip_tags() strict_types — TypeError for non-string $string (#4593, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

function expect_type_error(callable $fn): void {
    try {
        $fn();
        echo "no\n";
    } catch (TypeError $e) {
        echo "yes\n";
    }
}

expect_type_error(static fn () => strip_tags([]));
expect_type_error(static fn () => strip_tags(new stdClass()));
expect_type_error(static fn () => strip_tags(123));
echo strip_tags('<b>x</b>'), "\n";
--EXPECT--
yes
yes
yes
x

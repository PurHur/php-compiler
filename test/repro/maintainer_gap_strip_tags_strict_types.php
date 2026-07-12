<?php
// strip_tags() under declare(strict_types=1) must TypeError on non-string $string (#4593, ext/standard/string.c)
declare(strict_types=1);

function expect_type_error(callable $fn): void
{
    try {
        $fn();
        echo "no throw\n";
    } catch (TypeError $e) {
        echo "TypeError\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

expect_type_error(static fn () => strip_tags([]));
expect_type_error(static fn () => strip_tags(new stdClass()));
expect_type_error(static fn () => strip_tags(123));
echo strip_tags('<b>x</b>'), "\n";

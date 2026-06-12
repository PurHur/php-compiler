--TEST--
Stdlib: filter_id() JIT — enum case operand TypeError (#3485, php-src-strict)
--FILE--
<?php
enum E: string { case X = 'validate_email'; }
try {
    filter_id(E::X);
    echo "ok\n";
} catch (Throwable $t) {
    echo $t::class, "\n";
    echo $t->getMessage(), "\n";
}
--EXPECT--
TypeError
filter_id(): Argument #1 ($name) must be of type string, E given

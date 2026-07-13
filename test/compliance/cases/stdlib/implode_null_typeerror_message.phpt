--TEST--
stdlib implode()/join(null) — overload-aware TypeError names $separator (#18632, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    implode(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
implode(): Argument #1 ($separator) must be of type array|string, null given
join(): Argument #1 ($separator) must be of type array|string, null given

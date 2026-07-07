--TEST--
stdlib strpos()/substr_compare() — null needle TypeError under strict_types (#17270, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['strpos', 'substr_compare'] as $fn) {
    try {
        if ('strpos' === $fn) {
            $fn('haystack', null);
        } else {
            $fn('a', null, 0);
        }
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
strpos: strpos(): Argument #2 ($needle) must be of type string, null given
substr_compare: substr_compare(): Argument #2 ($needle) must be of type string, null given

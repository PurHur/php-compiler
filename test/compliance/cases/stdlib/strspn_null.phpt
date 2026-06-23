--TEST--
stdlib strspn()/strcspn() — null haystack TypeError (#10992, ext/standard/string.c)
--FILE--
<?php
foreach (['strspn', 'strcspn'] as $fn) {
    try {
        $fn(null, 'abc');
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
%A
strspn: strspn(): Argument #1 ($string) must be of type string, null given
strcspn: strcspn(): Argument #1 ($string) must be of type string, null given

--TEST--
AOT: tempnam(null, 'x') — TypeError on 8.4 forward profile (#20960)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    tempnam(null, 'x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
tempnam(): Argument #1 ($directory) must be of type string, null given

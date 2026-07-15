--TEST--
stdlib tempnam() null directory under strict_types — TypeError (#18244, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
try {
    tempnam(null, 'phpc');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
tempnam(): Argument #1 ($directory) must be of type string, null given

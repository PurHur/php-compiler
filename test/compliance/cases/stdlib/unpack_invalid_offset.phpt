--TEST--
stdlib unpack() invalid offset throws ValueError (#10516)
--FILE--
<?php
declare(strict_types=1);
try {
    unpack('C', "\x01", 99);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
unpack(): Argument #3 ($offset) must be contained in argument #2 ($data)

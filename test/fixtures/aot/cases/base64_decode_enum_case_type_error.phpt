--TEST--
AOT base64_decode() — enum case operand TypeError (#5942)
--FILE--
<?php
declare(strict_types=1);

enum Es: string { case B = 'eA=='; }

try {
    base64_decode(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
base64_decode(): Argument #1 ($string) must be of type string, Es given

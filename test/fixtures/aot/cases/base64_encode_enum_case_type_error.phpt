--TEST--
AOT base64_encode() — enum case operand TypeError (#5942)
--FILE--
<?php
declare(strict_types=1);

enum Es: string { case B = 'eA=='; }

try {
    base64_encode(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
base64_encode(): Argument #1 ($string) must be of type string, Es given

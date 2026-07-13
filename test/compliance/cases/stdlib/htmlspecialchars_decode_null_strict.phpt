--TEST--
stdlib htmlspecialchars_decode() null $string TypeError under declare(strict_types=1) (#18633, ext/standard/html.c)
--FILE--
<?php
declare(strict_types=1);

try {
    htmlspecialchars_decode(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
htmlspecialchars_decode(): Argument #1 ($string) must be of type string, null given

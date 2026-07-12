--TEST--
stdlib imagecreatefromstring() enum operand TypeError (#6215, php-src-strict)
--FILE--
<?php
declare(strict_types=1);
enum Color: string { case Red = 'red'; }
try {
    imagecreatefromstring(Color::Red);
    echo "no_error\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: imagecreatefromstring(): Argument #1 ($image) must be of type string, Color given

--TEST--
stdlib hexdec() JIT — backed enum case TypeError (#5875)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case B = 'ff';
}

try {
    hexdec(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hexdec(): Argument #1 ($hex_string) must be of type string, Es given

--TEST--
stdlib escapeshellcmd() JIT — backed enum case TypeError (#5876)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case A = 'x';
}

try {
    escapeshellcmd(Es::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
escapeshellcmd(): Argument #1 ($command) must be of type string, Es given

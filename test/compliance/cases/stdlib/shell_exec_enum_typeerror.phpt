--TEST--
stdlib shell_exec() — enum case command TypeError (#5931, ext/standard/exec.c, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string
{
    case A = 'echo hi';
}

try {
    shell_exec(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
shell_exec(): Argument #1 ($command) must be of type string, E given

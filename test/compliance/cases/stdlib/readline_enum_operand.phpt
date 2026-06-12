--TEST--
stdlib readline() — enum case prompt TypeError (#6080, ext/readline/readline.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    readline(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$r = readline(null);
echo ($r === false || is_string($r)) ? "null ok\n" : "null bad\n";

$r = readline('> ');
echo ($r === false || is_string($r)) ? "prompt ok\n" : "prompt bad\n";
--EXPECT--
readline(): Argument #1 ($prompt) must be of type ?string, E given
null ok
> prompt ok

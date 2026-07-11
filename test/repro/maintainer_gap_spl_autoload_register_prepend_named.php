<?php

declare(strict_types=1);

function autoload_prepend_first(string $class): void
{
}

function autoload_prepend_second(string $class): void
{
}

spl_autoload_register('autoload_prepend_first');
spl_autoload_register('autoload_prepend_second', prepend: true);
$funcs = spl_autoload_functions();
$ok = $funcs[0] === 'autoload_prepend_second' && $funcs[1] === 'autoload_prepend_first';
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);

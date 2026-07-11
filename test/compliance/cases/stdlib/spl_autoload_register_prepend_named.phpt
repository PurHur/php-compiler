--TEST--
stdlib spl_autoload_register() named prepend: skips default throw (#12377, ext/spl/php_spl.c)
--FILE--
<?php
function autoload_named_first(string $class): void
{
}
function autoload_named_second(string $class): void
{
}
spl_autoload_register('autoload_named_first');
spl_autoload_register('autoload_named_second', prepend: true);
$funcs = spl_autoload_functions();
$ok = $funcs[0] === 'autoload_named_second' && $funcs[1] === 'autoload_named_first';
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
ok

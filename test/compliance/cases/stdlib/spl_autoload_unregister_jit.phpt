--TEST--
stdlib spl_autoload_unregister() JIT removes registered function callback (issue #3580, #1776)
--FILE--
<?php
$invoked = 0;
function autoload_once(string $class): void
{
    global $invoked;
    if ('JitGone' === $class) {
        ++$invoked;
        class JitGone {}
    }
}
spl_autoload_register('autoload_once');
var_export(spl_autoload_unregister('autoload_once'));
echo "\n";
class_exists('JitGone', true);
echo $invoked, "\n";
--EXPECT--
true
0

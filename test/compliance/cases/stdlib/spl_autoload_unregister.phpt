--TEST--
stdlib spl_autoload_unregister() removes registered closure callback (issue #3580, ext/spl/php_spl.c)
--FILE--
<?php
$hits = 0;
$cb = function (string $class) use (&$hits): void {
  if ('Gone' === $class) {
    ++$hits;
    class Gone {}
  }
};
spl_autoload_register($cb);
spl_autoload_call('Gone');
echo $hits, "\n";
var_export(spl_autoload_unregister($cb));
echo "\n";
var_export(spl_autoload_unregister($cb));
echo "\n";
spl_autoload_call('Gone');
echo $hits, "\n";
--EXPECT--
1
true
false
1

--TEST--
stdlib var_export(null) compile-time null literal after ob_start() (#19066, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$literal = (static function (): string {
    ob_start();
    var_export(null);
    return (string) ob_get_clean();
})();

$variable = (static function (): string {
    $v = null;
    ob_start();
    var_export($v);
    return (string) ob_get_clean();
})();

echo $literal, "\n";
echo $variable, "\n";
--EXPECT--
NULL
NULL

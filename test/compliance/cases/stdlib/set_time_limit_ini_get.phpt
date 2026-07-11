--TEST--
Stdlib: set_time_limit() / ini_set('max_execution_time') update ini_get (#12481, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
$ok = true;
if ('0' !== ini_get('max_execution_time')) {
    $ok = false;
}
if (!set_time_limit(30)) {
    $ok = false;
}
if ('30' !== ini_get('max_execution_time')) {
    $ok = false;
}
if (false === ini_set('max_execution_time', '45')) {
    $ok = false;
}
if ('45' !== ini_get('max_execution_time')) {
    $ok = false;
}
if (!set_time_limit(-1)) {
    $ok = false;
}
if ('-1' !== ini_get('max_execution_time')) {
    $ok = false;
}
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
ok

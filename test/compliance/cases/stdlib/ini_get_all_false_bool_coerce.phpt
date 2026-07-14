--TEST--
stdlib ini_get_all(false) — bool coerces to "" outside strict_types (#18870, ext/standard/ini.c)
--FILE--
<?php
$errs = [];
set_error_handler(static function (int $n, string $m) use (&$errs): bool {
    $errs[] = $m;

    return true;
});
$r = ini_get_all(false);
echo ($r === false) ? "false_ok\n" : "false_fail\n";
echo isset($errs[0]) && str_contains($errs[0], 'Extension "" cannot be found') ? "warn_ok\n" : "warn_fail\n";
?>
--EXPECT--
false_ok
warn_ok

--TEST--
stream_context_set_option null wrapper soft-DEP + true (#31422)
--FILE--
<?php
$c = stream_context_create();
$r = @stream_context_set_option($c, null, 'a', 'b');
echo $r === true ? "true\n" : "fail\n";

$seen = false;
set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
    if ($errno === E_DEPRECATED
        && str_contains($errstr, 'Passing null to parameter #2 ($wrapper_or_options) of type array|string is deprecated')
    ) {
        $seen = true;
    }
    return true;
});
$c2 = stream_context_create();
$r2 = stream_context_set_option($c2, null, 'x', 'y');
restore_error_handler();
echo ($seen && $r2 === true) ? "dep_ok\n" : "dep_fail\n";
--EXPECT--
true
dep_ok

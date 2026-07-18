--TEST--
stdlib gettext(null) still deprecates+coerces on 8.2 profile (#20209, ext/gettext/gettext.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
$result = gettext(null);
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no && str_contains($str, 'gettext(): Passing null to parameter #1 ($msgid) of type string is deprecated')) {
        $depr = 1;
    }
}
echo 'result=', var_export($result, true), ' depr=', $depr, "\n";
?>
--EXPECT--
result='' depr=1

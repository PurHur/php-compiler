--TEST--
stdlib ctype_*(null) still deprecates+false on 8.2 profile (#20252, #19717, ext/ctype/ctype.c)
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
$result = ctype_alnum(null);
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no && str_contains($str, 'ctype_alnum(): Argument of type null will be interpreted as string in the future')) {
        $depr = 1;
    }
}
echo 'result=', var_export($result, true), ' depr=', $depr, "\n";
?>
--EXPECT--
result=false depr=1

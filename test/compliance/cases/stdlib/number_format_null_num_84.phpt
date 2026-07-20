--TEST--
stdlib number_format(null) — null coerces to 0 on PHP 8.4 forward profile (#21429, ext/standard/formatted_print.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
$result = number_format(null);
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no
        && str_contains($str, 'number_format(): Passing null to parameter #1 ($num) of type float is deprecated')
    ) {
        $depr = 1;
    }
}
echo $result, "\n";
echo 'depr=', $depr, "\n";
?>
--EXPECT--
0
depr=1

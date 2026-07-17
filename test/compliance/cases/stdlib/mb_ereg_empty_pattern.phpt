--TEST--
stdlib mb_ereg()/mb_eregi() empty pattern — ValueError (ext/mbstring/php_mbregex.c, #20261)
--FILE--
<?php
foreach (['mb_ereg', 'mb_eregi'] as $fn) {
    try {
        $fn('', 'abc');
        echo "$fn empty: uncaught\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
try {
    mb_ereg(null, 'abc');
    echo "mb_ereg null: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no
        && str_contains($str, 'mb_ereg(): Passing null to parameter #1 ($pattern) of type string is deprecated')
    ) {
        $depr = 1;
    }
}
echo 'depr=', $depr, "\n";
?>
--EXPECT--
mb_ereg(): Argument #1 ($pattern) must not be empty
mb_eregi(): Argument #1 ($pattern) must not be empty
mb_ereg(): Argument #1 ($pattern) must not be empty
depr=1

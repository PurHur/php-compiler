--TEST--
stdlib getimagesizefromstring(null) soft-null DEP+notice+false on 8.4 (#21492, reverts #20353, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seenDep = 0;
$seenNotice = 0;
set_error_handler(static function (int $no, string $msg) use (&$seenDep, &$seenNotice): bool {
    if (E_DEPRECATED === $no) {
        $seenDep++;
        return true;
    }
    if (E_NOTICE === $no || E_WARNING === $no) {
        if (str_contains($msg, 'Error reading from !')) {
            $seenNotice++;
        }
        return true;
    }
    return false;
});
$result = getimagesizefromstring(null);
restore_error_handler();
var_export($result);
echo "\n";
echo 'depr=', (int) ($seenDep >= 1), "\n";
echo 'notice=', (int) ($seenNotice >= 1), "\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$size = getimagesizefromstring($png);
echo (int) $size[0], 'x', (int) $size[1], "\n";
?>
--EXPECT--
false
depr=1
notice=1
1x1

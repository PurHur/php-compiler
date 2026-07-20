--TEST--
iconv() JIT null encoding TypeError; null string soft-null (#19387 / #21197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
        return true;
    }
    return false;
});
foreach ([
    'from' => static fn () => iconv(null, 'UTF-8', 'x'),
    'to' => static fn () => iconv('UTF-8', null, 'x'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
try {
    echo 'string=', var_export(iconv('UTF-8', 'UTF-8', null), true), "\n";
} catch (TypeError $e) {
    echo "string: TypeError\n";
}
restore_error_handler();
echo 'string_depr=', (int) (count($seen) >= 1), "\n";
?>
--EXPECT--
from: iconv(): Argument #1 ($from_encoding) must be of type string, null given
to: iconv(): Argument #2 ($to_encoding) must be of type string, null given
string=''
string_depr=1

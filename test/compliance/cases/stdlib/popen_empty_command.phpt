--TEST--
Stdlib: popen('')/null returns stream — Zend allows empty (re-#24688, #24940)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if ($n === E_DEPRECATED) {
        echo 'DEP: ', $m, "\n";
    }
    return true;
});
$h = popen('', 'r');
echo 'empty=', (int) (false !== $h && (is_resource($h) || is_object($h))), "\n";
if (false !== $h) {
    pclose($h);
}
$h = popen(null, 'r');
echo 'null=', (int) (false !== $h && (is_resource($h) || is_object($h))), "\n";
if (false !== $h) {
    pclose($h);
}
?>
--EXPECT--
empty=1
DEP: popen(): Passing null to parameter #1 ($command) of type string is deprecated
null=1

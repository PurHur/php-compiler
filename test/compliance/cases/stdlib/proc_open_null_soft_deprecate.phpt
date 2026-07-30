--TEST--
stdlib proc_open(null) — DEP + coerce to empty command, returns process resource (#25113, re-#18901)
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $str) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        $deps[] = $str;
    }
    return true;
});
$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'is_resource=', (int) is_resource($result), "\n";
if (is_resource($result)) {
    @proc_terminate($result);
}
$found = false;
foreach ($deps as $msg) {
    if (str_contains($msg, 'proc_open(): Passing null to parameter #1 ($command) of type array|string is deprecated')) {
        $found = true;
        break;
    }
}
echo 'dep=', $found ? '1' : '0', "\n";
?>
--EXPECT--
is_resource=1
dep=1

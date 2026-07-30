--TEST--
stdlib proc_open(null) — coerces to empty command, returns process resource (#25113 / #18901, ext/standard/proc_open.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    return E_DEPRECATED === $no;
});
$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'is_resource=', (int) is_resource($result), "\n";
if (is_resource($result)) {
    // Empty descriptor_spec leaves a running shell; terminate so the VM process can exit.
    @proc_terminate($result);
}
?>
--EXPECT--
is_resource=1

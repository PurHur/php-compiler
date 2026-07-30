<?php
/**
 * #25113 — proc_open(null) soft-deprecates and opens (Zend 8.2); not TypeError.
 * Sibling exec builtins still ValueError after DEP; popen also soft-opens.
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $str) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        $deps[] = $str;
    }

    return true;
});

foreach (['shell_exec', 'system', 'passthru'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "$fn(null): expected ValueError\n");
        exit(1);
    } catch (ValueError) {
        echo "$fn(null): ValueError\n";
    } catch (TypeError) {
        fwrite(STDERR, "$fn(null): got TypeError, expected ValueError\n");
        exit(1);
    }
}

$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'proc_open: '.(is_resource($result) ? 'resource' : (null === $result ? 'NULL' : 'other'))."\n";
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
echo 'proc_open_dep=', $found ? '1' : '0', "\n";

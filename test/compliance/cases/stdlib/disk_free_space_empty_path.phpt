--TEST--
stdlib disk_free_space('') / disk_total_space('') — false without warning (#18387, ext/standard/filestat.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$free = disk_free_space('');
$total = disk_total_space('');

if ($free !== false || $total !== false) {
    echo 'bad_result', "\n";
} elseif (0 !== count($warnings)) {
    echo 'bad_warnings', "\n";
} else {
    echo 'ok', "\n";
}
--EXPECT--
ok

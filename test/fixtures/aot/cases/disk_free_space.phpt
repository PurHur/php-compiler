--TEST--
AOT: disk_free_space() / disk_total_space() via statvfs
--FILE--
<?php
$path = sys_get_temp_dir();
$free = disk_free_space($path);
$total = disk_total_space($path);
if ($free === false || $total === false) {
    echo 'fail', "\n";
} else {
    echo 'ok', "\n";
}
$aliasFree = diskfreespace($path);
if ($aliasFree === false) {
    echo 'alias_bad', "\n";
} else {
    echo 'alias_ok', "\n";
}
$bad = disk_free_space('/no/such/phpc-disk-space-path');
if ($bad > 0.0) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
alias_ok
gone

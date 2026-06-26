--TEST--
stdlib disk_free_space() / disk_total_space() and aliases
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
$aliasTotal = disk_total_space($path);
if ($aliasFree === false || $aliasTotal === false) {
    echo 'alias_bad', "\n";
} else {
    echo 'alias_ok', "\n";
}
$nullFree = disk_free_space(null);
$dotFree = disk_free_space('.');
if ($nullFree === false || $dotFree === false) {
    echo 'null_bad', "\n";
} else {
    echo 'null_ok', "\n";
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
null_ok
gone

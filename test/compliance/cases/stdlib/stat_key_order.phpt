--TEST--
Stdlib: stat()/lstat() — numeric indices precede string aliases (#11368, filestat.c)
--FILE--
<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'phpc_stat_key_order_');
if (!is_string($path)) {
    echo "skip\n";
    return;
}
touch($path);
$s = stat($path);
echo 'first_key=' . array_key_first($s) . "\n";
$keys = array_keys($s);
echo 'first_six=' . implode(',', array_slice($keys, 0, 6)) . "\n";
echo 'dev_match=' . ($s[0] === $s['dev'] ? 'yes' : 'no') . "\n";
$l = lstat($path);
echo 'lstat_first=' . array_key_first($l) . "\n";
@unlink($path);
--EXPECT--
first_key=0
first_six=0,1,2,3,4,5
dev_match=yes
lstat_first=0

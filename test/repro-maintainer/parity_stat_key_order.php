<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'phpc_stat_key_order_');
if (!is_string($path)) {
    echo "skip\n";
    exit(0);
}
touch($path);
$s = stat($path);
echo 'first_key=' . array_key_first($s) . "\n";
$keys = array_keys($s);
echo 'first_six=' . implode(',', array_slice($keys, 0, 6)) . "\n";
echo 'dev_match=' . ($s[0] === $s['dev'] ? 'yes' : 'no') . "\n";
@unlink($path);

<?php
// ZipArchive::statName / statIndex — AOT must match VM (#35504 leftover of #35500 / #19873).
$path = sys_get_temp_dir() . '/phpc_zip_35504_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'BB');

$sn = $z->statName('a.txt');
echo 'sn=';
if (is_array($sn)) {
    // Omit mtime — VM/AOT each call time() in separate processes (#35504).
    echo $sn['name'], '|', $sn['index'], '|', $sn['size'], '|', $sn['crc'], '|', $sn['comp_size'], '|', $sn['comp_method'], '|', $sn['encryption_method'];
} else {
    var_export($sn);
}
echo "\n";

$si = $z->statIndex(1);
echo 'si=';
if (is_array($si)) {
    echo $si['name'], '|', $si['index'], '|', $si['size'], '|', $si['crc'];
} else {
    var_export($si);
}
echo "\n";

echo 'miss=';
var_export($z->statName('nope'));
echo "\n";
echo 'badidx=';
var_export($z->statIndex(9));
echo "\n";

$z->close();
@unlink($path);

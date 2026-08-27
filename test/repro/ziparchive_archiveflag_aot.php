<?php
// ZipArchive::setArchiveFlag/getArchiveFlag — AOT must match VM (#35522 leftover of #35515 / #21831).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/phpc_zip_aflag_' . getmypid() . '.zip';
@unlink($p);
$z->open($p, ZipArchive::CREATE);
$z->addFromString('a.txt', 'hi');
echo 'set=' . var_export($z->setArchiveFlag(ZipArchive::AFL_RDONLY, 1), true) . "\n";
echo 'get=' . var_export($z->getArchiveFlag(ZipArchive::AFL_RDONLY), true) . "\n";
echo 'clear=' . var_export($z->setArchiveFlag(ZipArchive::AFL_RDONLY, 0), true) . "\n";
echo 'get2=' . var_export($z->getArchiveFlag(ZipArchive::AFL_RDONLY), true) . "\n";
echo 'bad=' . var_export($z->setArchiveFlag(1, 1), true) . "\n";
echo 'torrent=' . var_export($z->setArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP, 1), true) . "\n";
echo 'get_t=' . var_export($z->getArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP), true) . "\n";
$z->close();
@unlink($p);

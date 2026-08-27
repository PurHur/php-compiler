<?php
// ZipArchive::setEncryptionName/setEncryptionIndex — AOT must match VM (#35503 leftover of #35500).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/phpc_zip_35503_' . getmypid() . '.zip';
@unlink($p);
$z->open($p, ZipArchive::CREATE);
$z->addFromString('a.txt', 'hi');
$z->addFromString('b.txt', 'yo');
echo 'sen_aes=' . var_export($z->setEncryptionName('a.txt', ZipArchive::EM_AES_256), true) . "\n";
echo 'sen_none=' . var_export($z->setEncryptionName('a.txt', ZipArchive::EM_NONE), true) . "\n";
echo 'sen_miss=' . var_export($z->setEncryptionName('missing.txt', ZipArchive::EM_AES_128), true) . "\n";
$z->setPassword('secret');
echo 'sei_session=' . var_export($z->setEncryptionIndex(1, ZipArchive::EM_AES_256), true) . "\n";
echo 'sei_explicit=' . var_export($z->setEncryptionIndex(1, ZipArchive::EM_TRAD_PKWARE, 'other'), true) . "\n";
echo 'sei_bad=' . var_export($z->setEncryptionIndex(9, ZipArchive::EM_AES_256), true) . "\n";
$z->close();
@unlink($p);

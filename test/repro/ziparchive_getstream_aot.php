<?php
// ZipArchive::getStream / getStreamIndex / getStreamName — AOT must return readable streams (#35534 leftover of #35531 / #20378).
$path = sys_get_temp_dir() . '/phpc_zip_35534_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('a.txt', 'hello');
$z->addFromString('b.txt', 'world');

$s = $z->getStream('a.txt');
echo 'gs=', (is_resource($s) ? 'yes' : 'no'), ' data=', stream_get_contents($s), "\n";
fclose($s);

$s2 = $z->getStreamIndex(1);
echo 'gsi=', (is_resource($s2) ? 'yes' : 'no'), ' data=', stream_get_contents($s2), "\n";
fclose($s2);

$s3 = $z->getStreamName('a.txt');
echo 'gsn=', (is_resource($s3) ? 'yes' : 'no'), ' data=', stream_get_contents($s3), "\n";
fclose($s3);

echo 'miss=', var_export($z->getStream('missing.txt'), true), "\n";
$z->close();
@unlink($path);

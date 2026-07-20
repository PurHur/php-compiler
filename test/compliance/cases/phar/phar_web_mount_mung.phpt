--TEST--
ext/phar Phar::webPhar/mount/mungServer static APIs (#21327, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['webPhar', 'mount', 'mungServer'] as $m) {
    echo $m, '=', method_exists('Phar', $m) ? 'Y' : 'N', "\n";
    echo 'PharData_', $m, '=', method_exists('PharData', $m) ? 'Y' : 'N', "\n";
}

try {
    Phar::webPhar();
    echo "web_fail=N\n";
} catch (PharException $e) {
    echo 'web_fail=', str_contains($e->getMessage(), 'phar archive') ? 'Y' : 'N', "\n";
}

try {
    Phar::mungServer([]);
    echo "mung_empty=N\n";
} catch (PharException $e) {
    echo 'mung_empty=', str_contains($e->getMessage(), 'No values passed') ? 'Y' : 'N', "\n";
}

Phar::mungServer(['REQUEST_URI', 'SCRIPT_NAME']);
echo "mung_ok=Y\n";

$dir = sys_get_temp_dir() . '/phar21327_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
$external = $dir . '/mounted_payload.txt';
file_put_contents($external, 'mounted-bytes');
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->addFromString('inner.txt', 'inside-phar');
unset($phar);

Phar::loadPhar($pharPath, 'appalias');
Phar::mount('phar://appalias/mounted.txt', $external);
$viaMount = file_get_contents('phar://appalias/mounted.txt');
echo 'mount_read=', ($viaMount === 'mounted-bytes') ? 'Y' : 'N', "\n";
?>
--EXPECT--
webPhar=Y
PharData_webPhar=Y
mount=Y
PharData_mount=Y
mungServer=Y
PharData_mungServer=Y
web_fail=Y
mung_empty=Y
mung_ok=Y
mount_read=Y

--TEST--
stdlib eio_stat/mkdir/unlink/readdir/chmod + EIO_READ (#27837)
--ENV--
PHP_COMPILER_ENABLE_EIO=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\ext\eio\EioExtensionPolicy::advertisesExtension()) {
    die('skip eio withheld (#6442)');
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['eio_stat','eio_mkdir','eio_unlink','eio_readdir','eio_chmod'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
echo 'EIO_READ=', defined('EIO_READ') ? (string) EIO_READ : 'N', "\n";
echo 'EIO_READDIR_DENTS=', defined('EIO_READDIR_DENTS') ? (string) EIO_READDIR_DENTS : 'N', "\n";

$base = sys_get_temp_dir().'/php-compiler-eio-phpt-27837-'.getmypid();
@mkdir($base);
$file = $base.'/a.txt';
file_put_contents($file, 'hi');
$got = [];
eio_stat($file, EIO_PRI_DEFAULT, function ($d, $r) use (&$got) {
    $got['size'] = is_array($r) ? (int) ($r['size'] ?? -1) : -1;
});
eio_mkdir($base.'/d', 0755, EIO_PRI_DEFAULT, function ($d, $r) use (&$got) {
    $got['mkdir'] = (int) $r;
});
eio_readdir($base, 0, EIO_PRI_DEFAULT, function ($d, $r) use (&$got) {
    $got['names'] = is_array($r) && isset($r['names']) ? count($r['names']) : -1;
});
eio_chmod($file, 0600, EIO_PRI_DEFAULT, function ($d, $r) use (&$got) {
    $got['chmod'] = (int) $r;
});
eio_unlink($file, EIO_PRI_DEFAULT, function ($d, $r) use (&$got) {
    $got['unlink'] = (int) $r;
});
while (eio_nreqs()) {
    eio_poll();
}
echo 'size=', $got['size'] ?? 'x', "\n";
echo 'mkdir=', $got['mkdir'] ?? 'x', "\n";
echo 'names=', ($got['names'] ?? 0) >= 1 ? 'ok' : 'bad', "\n";
echo 'chmod=', $got['chmod'] ?? 'x', "\n";
echo 'unlink=', $got['unlink'] ?? 'x', "\n";
@rmdir($base.'/d');
@rmdir($base);
?>
--EXPECT--
eio_stat=Y
eio_mkdir=Y
eio_unlink=Y
eio_readdir=Y
eio_chmod=Y
EIO_READ=6
EIO_READDIR_DENTS=1
size=2
mkdir=0
names=ok
chmod=0
unlink=0

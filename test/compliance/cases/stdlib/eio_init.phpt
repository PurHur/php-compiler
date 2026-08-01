--TEST--
stdlib eio_init/read/write/poll (#6442)
--ENV--
PHP_COMPILER_ENABLE_EIO=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\eio\EioExtensionPolicy::advertisesExtension()) {
    die('skip eio withheld (#6442)');
}
--FILE--
<?php
declare(strict_types=1);

echo function_exists('eio_init') ? '1' : '0';
echo function_exists('eio_read') ? '1' : '0';
echo function_exists('eio_write') ? '1' : '0';
echo function_exists('eio_poll') ? '1' : '0';
echo extension_loaded('eio') ? '1' : '0';
echo "\n";

eio_init();
$path = sys_get_temp_dir().'/php-compiler-eio-phpt-'.getmypid().'.txt';
@unlink($path);
$got = '';

eio_open($path, EIO_O_RDWR | EIO_O_CREAT, 0644, EIO_PRI_DEFAULT, function ($data, $result) use (&$got) {
    $fd = (int) $result;
    eio_write($fd, 'hello eio', 9, 0, EIO_PRI_DEFAULT, function ($fd2, $n) use (&$got) {
        eio_read((int) $fd2, 9, 0, EIO_PRI_DEFAULT, function ($fd3, $bytes) use (&$got) {
            $got = is_string($bytes) ? $bytes : '';
            eio_close((int) $fd3);
        }, $fd2);
    }, $fd);
}, $path);

while (eio_nreqs()) {
    eio_poll();
}
echo $got === 'hello eio' ? "ok\n" : "bad:$got\n";
@unlink($path);
?>
--EXPECT--
11111
ok

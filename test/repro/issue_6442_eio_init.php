<?php

declare(strict_types=1);

/**
 * Issue #6442 — ext/eio phase-1 existence + open/write/read/poll callbacks.
 *
 * Run: PHP_COMPILER_ENABLE_EIO=1 php bin/vm.php test/repro/issue_6442_eio_init.php
 */

foreach (['eio_init', 'eio_read', 'eio_write', 'eio_poll', 'eio_nreqs'] as $fn) {
    echo $fn.': '.(function_exists($fn) ? 'yes' : 'MISSING')."\n";
}
echo extension_loaded('eio') ? "ext=1\n" : "ext=0\n";

eio_init();

$path = sys_get_temp_dir().'/php-compiler-eio-'.getmypid().'.txt';
@unlink($path);

$got = '';

eio_open($path, EIO_O_RDWR | EIO_O_CREAT, 0644, EIO_PRI_DEFAULT, function ($data, $result) use (&$got) {
    $fd = (int) $result;
    if ($fd < 0) {
        echo "open_fail\n";

        return;
    }
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

<?php

declare(strict_types=1);

/**
 * AOT: umask() with a boxed/variable mask must compile and run (#33422).
 * Also covers thin-AOT libc umask(2) (host \\umask re-entered phpc_umask_*).
 */
$old = umask();
$m = 0077;
$prev = umask($m);
echo "prev=$prev\n";
$f = sys_get_temp_dir().'/phpc_umask_'.getmypid();
@unlink($f);
file_put_contents($f, 'x');
clearstatcache();
printf("mode=%o\n", fileperms($f) & 0777);
umask($old);
@unlink($f);

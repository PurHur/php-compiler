<?php
// Repro #32548 — leftover Type.php always-on dir-handle ABIs dropped.
// opendir/readdir/closedir must still compile and run (php-src ext/standard/dir.c).
$dh = @opendir('.');
if (false === $dh) {
    echo "opendir_fail\n";
    exit(0);
}
$first = readdir($dh);
echo is_string($first) ? "readdir_ok\n" : "readdir_bad\n";
closedir($dh);
echo "closedir_ok\n";

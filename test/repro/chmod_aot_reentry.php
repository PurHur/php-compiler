<?php
// Repro #33418 — AOT chmod must set mode via libc chmod(2), not re-enter host \chmod.
$f = tempnam(sys_get_temp_dir(), 'cm33418');
if (false === $f) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
file_put_contents($f, 'x');
$ok = chmod($f, 0600);
$mode = fileperms($f) & 0777;
unlink($f);
echo ($ok && 0600 === $mode) ? "ok\n" : ('bad:'.($ok ? '1' : '0').':'.decoct($mode)."\n");

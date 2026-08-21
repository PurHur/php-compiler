<?php
// Repro #33412 — AOT unlink must delete via libc unlink(2), not re-enter host \unlink.
$f = tempnam(sys_get_temp_dir(), 'ul33412');
if (false === $f) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
file_put_contents($f, 'x');
$ok = unlink($f);
echo ($ok && !file_exists($f)) ? "ok\n" : "bad\n";

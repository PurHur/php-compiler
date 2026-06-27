<?php

declare(strict_types=1);

// Zend parity: tmpfile() supports flock/stream_supports_lock (ext/standard/flock.c).
$f = tmpfile();
if (false === $f) {
    fwrite(STDERR, "tmpfile failed\n");
    exit(1);
}
if (!stream_supports_lock($f)) {
    fwrite(STDERR, "stream_supports_lock false\n");
    exit(1);
}
if (!flock($f, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "flock LOCK_EX|LOCK_NB false\n");
    exit(1);
}
if (!flock($f, LOCK_UN)) {
    fwrite(STDERR, "flock LOCK_UN false\n");
    exit(1);
}
echo "ok\n";

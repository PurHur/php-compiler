<?php
/**
 * lchown/lchgrp/chroot excess argc → ArgumentCountError (#30568).
 * php-src: ext/standard/filestat.c / dir.c
 */
foreach ([
    'lchown_hi' => static fn () => lchown('/tmp', 0, 1),
    'lchown_lo' => static fn () => lchown('/tmp'),
    'lchgrp_hi' => static fn () => lchgrp('/tmp', 0, 1),
    'lchgrp_lo' => static fn () => lchgrp('/tmp'),
    'chroot_hi' => static fn () => chroot('/tmp', 1),
    'chroot_lo' => static fn () => chroot(),
] as $name => $call) {
    try {
        $call();
        echo $name, ":OK\n";
    } catch (ArgumentCountError $e) {
        echo $name, ':ArgumentCountError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

// Arity-ok paths must not raise ArgumentCountError (may warn / return false without root).
try {
    $r = @lchown('/no/such/path/30568', 0);
    echo 'ok_lchown:', (is_bool($r)) ? '1' : '0', "\n";
} catch (ArgumentCountError $e) {
    echo 'ok_lchown:0:', $e->getMessage(), "\n";
}
try {
    $r = @lchgrp('/no/such/path/30568', 0);
    echo 'ok_lchgrp:', (is_bool($r)) ? '1' : '0', "\n";
} catch (ArgumentCountError $e) {
    echo 'ok_lchgrp:0:', $e->getMessage(), "\n";
}
// Do not call chroot() arity-ok here — it can leave the process unusable after a failed attempt.
echo "ok_chroot_skipped:1\n";

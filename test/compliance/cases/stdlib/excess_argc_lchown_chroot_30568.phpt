--TEST--
stdlib: lchown/lchgrp/chroot ArgumentCountError wording (#30568)
--FILE--
<?php
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
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    $r = @lchown('/no/such/path/30568', 0);
    echo 'ok_lchown=', is_bool($r) ? '1' : '0', "\n";
} catch (ArgumentCountError $e) {
    echo 'ok_lchown=0', "\n";
}
try {
    $r = @lchgrp('/no/such/path/30568', 0);
    echo 'ok_lchgrp=', is_bool($r) ? '1' : '0', "\n";
} catch (ArgumentCountError $e) {
    echo 'ok_lchgrp=0', "\n";
}
echo 'ok_chroot_skipped=1', "\n";
--EXPECT--
lchown_hi ArgumentCountError: lchown() expects exactly 2 arguments, 3 given
lchown_lo ArgumentCountError: lchown() expects exactly 2 arguments, 1 given
lchgrp_hi ArgumentCountError: lchgrp() expects exactly 2 arguments, 3 given
lchgrp_lo ArgumentCountError: lchgrp() expects exactly 2 arguments, 1 given
chroot_hi ArgumentCountError: chroot() expects exactly 1 argument, 2 given
chroot_lo ArgumentCountError: chroot() expects exactly 1 argument, 0 given
ok_lchown=1
ok_lchgrp=1
ok_chroot_skipped=1

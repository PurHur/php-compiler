--TEST--
posix Linux extended POSIX_RLIMIT_* constants (php-src-strict, issue #24130)
--SKIPIF--
<?php if (!extension_loaded('posix') && !function_exists('posix_setrlimit')) die('skip no posix'); ?>
--FILE--
<?php
declare(strict_types=1);
$names = [
    'POSIX_RLIMIT_LOCKS',
    'POSIX_RLIMIT_SIGPENDING',
    'POSIX_RLIMIT_MSGQUEUE',
    'POSIX_RLIMIT_NICE',
    'POSIX_RLIMIT_RTPRIO',
    'POSIX_RLIMIT_RTTIME',
];
foreach ($names as $c) {
    echo $c, ' ', defined($c) ? 'Y=' . constant($c) : 'N', "\n";
}
echo 'eq ',
    (int) (POSIX_RLIMIT_LOCKS === 10),
    (int) (POSIX_RLIMIT_SIGPENDING === 11),
    (int) (POSIX_RLIMIT_MSGQUEUE === 12),
    (int) (POSIX_RLIMIT_NICE === 13),
    (int) (POSIX_RLIMIT_RTPRIO === 14),
    (int) (POSIX_RLIMIT_RTTIME === 15),
    "\n";
?>
--EXPECT--
POSIX_RLIMIT_LOCKS Y=10
POSIX_RLIMIT_SIGPENDING Y=11
POSIX_RLIMIT_MSGQUEUE Y=12
POSIX_RLIMIT_NICE Y=13
POSIX_RLIMIT_RTPRIO Y=14
POSIX_RLIMIT_RTTIME Y=15
eq 111111

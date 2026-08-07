--TEST--
pcntl SIGPOLL/SIGBABY + ILL_* constants (php-src-strict, issue #26759)
--SKIPIF--
<?php if (!extension_loaded('pcntl') && !function_exists('pcntl_signal')) die('skip no pcntl'); ?>
--FILE--
<?php
declare(strict_types=1);
$names = [
    'SIGPOLL', 'SIGBABY',
    'ILL_ILLOPC', 'ILL_ILLOPN', 'ILL_ILLADR', 'ILL_ILLTRP',
    'ILL_PRVOPC', 'ILL_PRVREG', 'ILL_COPROC', 'ILL_BADSTK',
];
foreach ($names as $c) {
    echo $c, ' ', defined($c) ? 'Y=' . constant($c) : 'N', "\n";
}
echo 'alias ', (int) (SIGPOLL === SIGIO), (int) (SIGBABY === SIGSYS), "\n";
echo 'eq ',
    (int) (ILL_ILLOPC === 1),
    (int) (ILL_BADSTK === 8),
    (int) (SIGPOLL === 29),
    (int) (SIGBABY === 31),
    "\n";
?>
--EXPECT--
SIGPOLL Y=29
SIGBABY Y=31
ILL_ILLOPC Y=1
ILL_ILLOPN Y=2
ILL_ILLADR Y=3
ILL_ILLTRP Y=4
ILL_PRVOPC Y=5
ILL_PRVREG Y=6
ILL_COPROC Y=7
ILL_BADSTK Y=8
alias 11
eq 1111

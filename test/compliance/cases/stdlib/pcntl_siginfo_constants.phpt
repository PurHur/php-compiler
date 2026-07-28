--TEST--
pcntl SI_*/CLD_*/SIGRT*/SIGCLD/SIGIOT siginfo constants (php-src-strict, issue #24111)
--SKIPIF--
<?php if (!extension_loaded('pcntl') && !function_exists('pcntl_signal')) die('skip no pcntl'); ?>
--FILE--
<?php
declare(strict_types=1);
$names = [
    'SI_USER', 'SI_KERNEL', 'SI_QUEUE', 'SI_TIMER', 'SI_MESGQ', 'SI_ASYNCIO', 'SI_SIGIO', 'SI_TKILL',
    'CLD_EXITED', 'CLD_KILLED', 'CLD_DUMPED', 'CLD_TRAPPED', 'CLD_STOPPED', 'CLD_CONTINUED',
    'SIGRTMIN', 'SIGRTMAX', 'SIGCLD', 'SIGIOT',
    'BUS_ADRALN', 'FPE_INTDIV', 'SEGV_MAPERR', 'POLL_IN', 'TRAP_BRKPT',
];
foreach ($names as $c) {
    echo $c, ' ', defined($c) ? 'Y=' . constant($c) : 'N', "\n";
}
echo 'alias ', (int) (SIGCLD === SIGCHLD), (int) (SIGIOT === SIGABRT), "\n";
echo 'eq ',
    (int) (SI_USER === 0),
    (int) (CLD_EXITED === 1),
    (int) (SIGRTMIN === 34),
    (int) (SIGRTMAX === 64),
    "\n";
?>
--EXPECT--
SI_USER Y=0
SI_KERNEL Y=128
SI_QUEUE Y=-1
SI_TIMER Y=-2
SI_MESGQ Y=-3
SI_ASYNCIO Y=-4
SI_SIGIO Y=-5
SI_TKILL Y=-6
CLD_EXITED Y=1
CLD_KILLED Y=2
CLD_DUMPED Y=3
CLD_TRAPPED Y=4
CLD_STOPPED Y=5
CLD_CONTINUED Y=6
SIGRTMIN Y=34
SIGRTMAX Y=64
SIGCLD Y=17
SIGIOT Y=6
BUS_ADRALN Y=1
FPE_INTDIV Y=1
SEGV_MAPERR Y=1
POLL_IN Y=1
TRAP_BRKPT Y=1
alias 11
eq 1111

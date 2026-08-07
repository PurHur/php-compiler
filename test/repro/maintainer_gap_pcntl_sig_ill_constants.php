<?php
declare(strict_types=1);

/**
 * Repro for #26759 — SIGPOLL/SIGBABY + ILL_* must match Zend when pcntl is loaded.
 * php-src: ext/pcntl/pcntl.stub.php (ifdef SIGPOLL / SIGSYS / ILL_*).
 */
if (!extension_loaded('pcntl') && !function_exists('pcntl_signal')) {
    fwrite(STDERR, "skip: pcntl not loaded\n");
    exit(0);
}

$need = [
    'SIGPOLL' => 29,
    'SIGBABY' => 31,
    'ILL_ILLOPC' => 1,
    'ILL_ILLOPN' => 2,
    'ILL_ILLADR' => 3,
    'ILL_ILLTRP' => 4,
    'ILL_PRVOPC' => 5,
    'ILL_PRVREG' => 6,
    'ILL_COPROC' => 7,
    'ILL_BADSTK' => 8,
];

$ok = true;
foreach ($need as $name => $want) {
    if (!defined($name)) {
        echo "$name UNDEF\n";
        $ok = false;
        continue;
    }
    $got = constant($name);
    echo "$name=$got\n";
    if ($got !== $want) {
        $ok = false;
    }
}

// Aliases
echo 'alias_poll=', (int) (defined('SIGPOLL') && defined('SIGIO') && SIGPOLL === SIGIO), "\n";
echo 'alias_baby=', (int) (defined('SIGBABY') && defined('SIGSYS') && SIGBABY === SIGSYS), "\n";

// Controls present on both sides
echo 'ctrl=', (int) (defined('SIGTERM') && defined('SIGKILL')), "\n";

// Do not grow 8.2 waitid phantoms in this fix (#26742 owns waitid surface)
$waitidPhantoms = ['P_PID', 'P_PGID', 'P_ALL', 'WEXITED', 'WSTOPPED', 'WNOWAIT'];
$extra = 0;
foreach ($waitidPhantoms as $w) {
    if (defined($w)) {
        ++$extra;
    }
}
echo "waitid_defined=$extra\n";

exit($ok ? 0 : 1);

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

/** POSIX signal numbers for Linux x86_64 (php-src ext/pcntl/pcntl.c; issue #6680). */
final class PcntlConstants
{
    public const SIGHUP = 1;
    public const SIGINT = 2;
    public const SIGQUIT = 3;
    public const SIGILL = 4;
    public const SIGTRAP = 5;
    public const SIGABRT = 6;
    public const SIGBUS = 7;
    public const SIGFPE = 8;
    public const SIGKILL = 9;
    public const SIGUSR1 = 10;
    public const SIGSEGV = 11;
    public const SIGUSR2 = 12;
    public const SIGPIPE = 13;
    public const SIGALRM = 14;
    public const SIGTERM = 15;
    public const SIGSTKFLT = 16;
    public const SIGCHLD = 17;
    public const SIGCONT = 18;
    public const SIGSTOP = 19;
    public const SIGTSTP = 20;
    public const SIGTTIN = 21;
    public const SIGTTOU = 22;
    public const SIGURG = 23;
    public const SIGXCPU = 24;
    public const SIGXFSZ = 25;
    public const SIGVTALRM = 26;
    public const SIGPROF = 27;
    public const SIGWINCH = 28;
    public const SIGIO = 29;
    public const SIGPWR = 30;
    public const SIGSYS = 31;

    /** waitpid(2) WNOHANG — return immediately if no child has exited (issue #3327). */
    public const WNOHANG = 1;

    /** waitid(2) / waitpid(2) option flags (php-src ext/pcntl/pcntl.stub.php). */
    public const WUNTRACED = 2;
    public const WCONTINUED = 8;
    public const WEXITED = 4;
    public const WSTOPPED = 2;
    public const WNOWAIT = 0x01000000;

    /** waitid(2) idtype (Linux; php-src HAVE_POSIX_IDTYPES). */
    public const P_ALL = 0;
    public const P_PID = 1;
    public const P_PGID = 2;

    /** sigprocmask(2) mode + handler dispositions (php-src signal.h). */
    public const SIG_BLOCK = 0;
    public const SIG_UNBLOCK = 1;
    public const SIG_SETMASK = 2;
    public const SIG_DFL = 0;
    public const SIG_IGN = 1;
    public const SIG_ERR = -1;

    /** Upper bound for valid POSIX signal numbers on Linux (php-src NSIG). */
    public const NSIG = 64;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'WNOHANG' => self::WNOHANG,
            'WUNTRACED' => self::WUNTRACED,
            'WCONTINUED' => self::WCONTINUED,
            'WEXITED' => self::WEXITED,
            'WSTOPPED' => self::WSTOPPED,
            'WNOWAIT' => self::WNOWAIT,
            'P_ALL' => self::P_ALL,
            'P_PID' => self::P_PID,
            'P_PGID' => self::P_PGID,
            'SIG_BLOCK' => self::SIG_BLOCK,
            'SIG_UNBLOCK' => self::SIG_UNBLOCK,
            'SIG_SETMASK' => self::SIG_SETMASK,
            'SIG_DFL' => self::SIG_DFL,
            'SIG_IGN' => self::SIG_IGN,
            'SIG_ERR' => self::SIG_ERR,
            'SIGHUP' => self::SIGHUP,
            'SIGINT' => self::SIGINT,
            'SIGQUIT' => self::SIGQUIT,
            'SIGILL' => self::SIGILL,
            'SIGTRAP' => self::SIGTRAP,
            'SIGABRT' => self::SIGABRT,
            'SIGBUS' => self::SIGBUS,
            'SIGFPE' => self::SIGFPE,
            'SIGKILL' => self::SIGKILL,
            'SIGUSR1' => self::SIGUSR1,
            'SIGSEGV' => self::SIGSEGV,
            'SIGUSR2' => self::SIGUSR2,
            'SIGPIPE' => self::SIGPIPE,
            'SIGALRM' => self::SIGALRM,
            'SIGTERM' => self::SIGTERM,
            'SIGSTKFLT' => self::SIGSTKFLT,
            'SIGCHLD' => self::SIGCHLD,
            'SIGCONT' => self::SIGCONT,
            'SIGSTOP' => self::SIGSTOP,
            'SIGTSTP' => self::SIGTSTP,
            'SIGTTIN' => self::SIGTTIN,
            'SIGTTOU' => self::SIGTTOU,
            'SIGURG' => self::SIGURG,
            'SIGXCPU' => self::SIGXCPU,
            'SIGXFSZ' => self::SIGXFSZ,
            'SIGVTALRM' => self::SIGVTALRM,
            'SIGPROF' => self::SIGPROF,
            'SIGWINCH' => self::SIGWINCH,
            'SIGIO' => self::SIGIO,
            'SIGPWR' => self::SIGPWR,
            'SIGSYS' => self::SIGSYS,
        ];
    }

    public static function isUncatchable(int $signo): bool
    {
        return self::SIGKILL === $signo || self::SIGSTOP === $signo;
    }

    public static function isValidSignal(int $signo): bool
    {
        return $signo >= 1 && $signo < self::NSIG;
    }
}

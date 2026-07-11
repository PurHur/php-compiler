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

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
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
}

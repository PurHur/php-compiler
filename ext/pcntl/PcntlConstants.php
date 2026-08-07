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

    /** Signal aliases (php-src ext/pcntl/pcntl.stub.php; #24111 / #26759). */
    public const SIGCLD = 17; // alias of SIGCHLD
    public const SIGIOT = 6;  // alias of SIGABRT
    public const SIGPOLL = 29; // alias of SIGIO (ifdef SIGPOLL)
    public const SIGBABY = 31; // alias of SIGSYS (ifdef SIGSYS)

    /** Real-time signal range (Linux x86_64; php-src SIGRTMIN/SIGRTMAX). */
    public const SIGRTMIN = 34;
    public const SIGRTMAX = 64;

    /**
     * siginfo_t.si_code selectors for pcntl_sigwaitinfo() (Linux signal.h; #24111).
     * Values match Zend 8.2 on this image (glibc).
     */
    public const SI_USER = 0;
    public const SI_KERNEL = 128;
    public const SI_QUEUE = -1;
    public const SI_TIMER = -2;
    public const SI_MESGQ = -3;
    public const SI_ASYNCIO = -4;
    public const SI_SIGIO = -5;
    public const SI_TKILL = -6;

    public const CLD_EXITED = 1;
    public const CLD_KILLED = 2;
    public const CLD_DUMPED = 3;
    public const CLD_TRAPPED = 4;
    public const CLD_STOPPED = 5;
    public const CLD_CONTINUED = 6;

    public const BUS_ADRALN = 1;
    public const BUS_ADRERR = 2;
    public const BUS_OBJERR = 3;

    public const FPE_INTDIV = 1;
    public const FPE_INTOVF = 2;
    public const FPE_FLTDIV = 3;
    public const FPE_FLTOVF = 4;
    public const FPE_FLTUND = 5;
    public const FPE_FLTRES = 6;
    public const FPE_FLTINV = 7;
    public const FPE_FLTSUB = 8;

    public const SEGV_MAPERR = 1;
    public const SEGV_ACCERR = 2;

    public const POLL_IN = 1;
    public const POLL_OUT = 2;
    public const POLL_MSG = 3;
    public const POLL_ERR = 4;
    public const POLL_PRI = 5;
    public const POLL_HUP = 6;

    public const TRAP_BRKPT = 1;
    public const TRAP_TRACE = 2;

    /**
     * siginfo_t.si_code selectors for SIGILL (Linux signal.h; php-src ILL_*; #26759).
     * Values match Zend 8.2 on glibc/Linux.
     */
    public const ILL_ILLOPC = 1;
    public const ILL_ILLOPN = 2;
    public const ILL_ILLADR = 3;
    public const ILL_ILLTRP = 4;
    public const ILL_PRVOPC = 5;
    public const ILL_PRVREG = 6;
    public const ILL_COPROC = 7;
    public const ILL_BADSTK = 8;

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

    /** getpriority(2) / setpriority(2) who (Linux; php-src sys/resource.h). */
    public const PRIO_PROCESS = 0;
    public const PRIO_PGRP = 1;
    public const PRIO_USER = 2;

    /** errno values exposed as PCNTL_E* (php-src ext/pcntl/pcntl.c; #20061). */
    public const PCNTL_EPERM = 1;
    public const PCNTL_ENOENT = 2;
    public const PCNTL_ESRCH = 3;
    public const PCNTL_EINTR = 4;
    public const PCNTL_EIO = 5;
    public const PCNTL_E2BIG = 7;
    public const PCNTL_ENOEXEC = 8;
    public const PCNTL_ECHILD = 10;
    public const PCNTL_EAGAIN = 11;
    public const PCNTL_ENOMEM = 12;
    public const PCNTL_EACCES = 13;
    public const PCNTL_EFAULT = 14;
    public const PCNTL_ENOTDIR = 20;
    public const PCNTL_EISDIR = 21;
    public const PCNTL_EINVAL = 22;
    public const PCNTL_ENFILE = 23;
    public const PCNTL_EMFILE = 24;
    public const PCNTL_ETXTBSY = 26;
    public const PCNTL_ENOSPC = 28;
    public const PCNTL_ENAMETOOLONG = 36;
    public const PCNTL_ELOOP = 40;
    public const PCNTL_ELIBBAD = 80;
    public const PCNTL_EUSERS = 87;

    /** unshare(2) / clone flags (Linux; php-src sched.h; #20061). */
    public const CLONE_NEWNS = 0x00020000;
    public const CLONE_NEWCGROUP = 0x02000000;
    public const CLONE_NEWUTS = 0x04000000;
    public const CLONE_NEWIPC = 0x08000000;
    public const CLONE_NEWUSER = 0x10000000;
    public const CLONE_NEWPID = 0x20000000;
    public const CLONE_NEWNET = 0x40000000;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'PRIO_PROCESS' => self::PRIO_PROCESS,
            'PRIO_PGRP' => self::PRIO_PGRP,
            'PRIO_USER' => self::PRIO_USER,
            'PCNTL_EPERM' => self::PCNTL_EPERM,
            'PCNTL_ENOENT' => self::PCNTL_ENOENT,
            'PCNTL_ESRCH' => self::PCNTL_ESRCH,
            'PCNTL_EINTR' => self::PCNTL_EINTR,
            'PCNTL_EIO' => self::PCNTL_EIO,
            'PCNTL_E2BIG' => self::PCNTL_E2BIG,
            'PCNTL_ENOEXEC' => self::PCNTL_ENOEXEC,
            'PCNTL_ECHILD' => self::PCNTL_ECHILD,
            'PCNTL_EAGAIN' => self::PCNTL_EAGAIN,
            'PCNTL_ENOMEM' => self::PCNTL_ENOMEM,
            'PCNTL_EACCES' => self::PCNTL_EACCES,
            'PCNTL_EFAULT' => self::PCNTL_EFAULT,
            'PCNTL_ENOTDIR' => self::PCNTL_ENOTDIR,
            'PCNTL_EISDIR' => self::PCNTL_EISDIR,
            'PCNTL_EINVAL' => self::PCNTL_EINVAL,
            'PCNTL_ENFILE' => self::PCNTL_ENFILE,
            'PCNTL_EMFILE' => self::PCNTL_EMFILE,
            'PCNTL_ETXTBSY' => self::PCNTL_ETXTBSY,
            'PCNTL_ENOSPC' => self::PCNTL_ENOSPC,
            'PCNTL_ENAMETOOLONG' => self::PCNTL_ENAMETOOLONG,
            'PCNTL_ELOOP' => self::PCNTL_ELOOP,
            'PCNTL_ELIBBAD' => self::PCNTL_ELIBBAD,
            'PCNTL_EUSERS' => self::PCNTL_EUSERS,
            'CLONE_NEWNS' => self::CLONE_NEWNS,
            'CLONE_NEWCGROUP' => self::CLONE_NEWCGROUP,
            'CLONE_NEWUTS' => self::CLONE_NEWUTS,
            'CLONE_NEWIPC' => self::CLONE_NEWIPC,
            'CLONE_NEWUSER' => self::CLONE_NEWUSER,
            'CLONE_NEWPID' => self::CLONE_NEWPID,
            'CLONE_NEWNET' => self::CLONE_NEWNET,
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
            'SIGCLD' => self::SIGCLD,
            'SIGIOT' => self::SIGIOT,
            'SIGPOLL' => self::SIGPOLL,
            'SIGBABY' => self::SIGBABY,
            'SIGRTMIN' => self::SIGRTMIN,
            'SIGRTMAX' => self::SIGRTMAX,
            'SI_USER' => self::SI_USER,
            'SI_KERNEL' => self::SI_KERNEL,
            'SI_QUEUE' => self::SI_QUEUE,
            'SI_TIMER' => self::SI_TIMER,
            'SI_MESGQ' => self::SI_MESGQ,
            'SI_ASYNCIO' => self::SI_ASYNCIO,
            'SI_SIGIO' => self::SI_SIGIO,
            'SI_TKILL' => self::SI_TKILL,
            'CLD_EXITED' => self::CLD_EXITED,
            'CLD_KILLED' => self::CLD_KILLED,
            'CLD_DUMPED' => self::CLD_DUMPED,
            'CLD_TRAPPED' => self::CLD_TRAPPED,
            'CLD_STOPPED' => self::CLD_STOPPED,
            'CLD_CONTINUED' => self::CLD_CONTINUED,
            'BUS_ADRALN' => self::BUS_ADRALN,
            'BUS_ADRERR' => self::BUS_ADRERR,
            'BUS_OBJERR' => self::BUS_OBJERR,
            'FPE_INTDIV' => self::FPE_INTDIV,
            'FPE_INTOVF' => self::FPE_INTOVF,
            'FPE_FLTDIV' => self::FPE_FLTDIV,
            'FPE_FLTOVF' => self::FPE_FLTOVF,
            'FPE_FLTUND' => self::FPE_FLTUND,
            'FPE_FLTRES' => self::FPE_FLTRES,
            'FPE_FLTINV' => self::FPE_FLTINV,
            'FPE_FLTSUB' => self::FPE_FLTSUB,
            'SEGV_MAPERR' => self::SEGV_MAPERR,
            'SEGV_ACCERR' => self::SEGV_ACCERR,
            'POLL_IN' => self::POLL_IN,
            'POLL_OUT' => self::POLL_OUT,
            'POLL_MSG' => self::POLL_MSG,
            'POLL_ERR' => self::POLL_ERR,
            'POLL_PRI' => self::POLL_PRI,
            'POLL_HUP' => self::POLL_HUP,
            'TRAP_BRKPT' => self::TRAP_BRKPT,
            'TRAP_TRACE' => self::TRAP_TRACE,
            'ILL_ILLOPC' => self::ILL_ILLOPC,
            'ILL_ILLOPN' => self::ILL_ILLOPN,
            'ILL_ILLADR' => self::ILL_ILLADR,
            'ILL_ILLTRP' => self::ILL_ILLTRP,
            'ILL_PRVOPC' => self::ILL_PRVOPC,
            'ILL_PRVREG' => self::ILL_PRVREG,
            'ILL_COPROC' => self::ILL_COPROC,
            'ILL_BADSTK' => self::ILL_BADSTK,
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

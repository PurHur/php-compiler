<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;

/** VM pcntl_signal()/pcntl_signal_dispatch() (php-src ext/pcntl/pcntl.c; issue #6680, #6545). */
final class VmPcntl
{
    /** @var array<int, array{kind: 'closure', closure: ClosureState, source: Variable}|array{kind: 'callable', callable: Variable}> */
    private static array $handlers = [];

    /** @var array<int, int> SIG_DFL / SIG_IGN dispositions when no user handler is registered */
    private static array $dispositions = [];

    /** @var list<int> Blocked signal numbers (VM fallback when host sigprocmask unavailable). */
    private static array $blockedSignals = [];

    private static bool $asyncSignals = false;

    /** @var list<int> */
    private static array $pending = [];

    public static function available(): bool
    {
        return true;
    }

    public static function processAvailable(): bool
    {
        return PcntlHostBridge::forkAvailable() || PcntlLibcThinAbi::processAvailable();
    }

    public static function fork(): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::fork();
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::fork();
        }

        throw new \Error('pcntl_fork() is not available in this compiler build');
    }

    public static function waitpid(int $pid, int &$status, int $options): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::waitpid($pid, $status, $options);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::waitpid($pid, $status, $options);
        }

        throw new \Error('pcntl_waitpid() is not available in this compiler build');
    }

    /** php-src pcntl_wait() — waitpid(-1, …) (ext/pcntl/pcntl.c; #19565). */
    public static function wait(int &$status, int $options): int
    {
        return self::waitpid(-1, $status, $options);
    }

    public static function alarm(int $seconds): int
    {
        if (PcntlHostBridge::alarmAvailable()) {
            return PcntlHostBridge::alarm($seconds);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::alarm($seconds);
        }

        throw new \Error('pcntl_alarm() is not available in this compiler build');
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public static function exec(string $path, array $args, array $env): bool
    {
        if (PcntlHostBridge::execAvailable()) {
            return PcntlHostBridge::exec($path, $args, $env);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::exec($path, $args, $env);
        }

        throw new \Error('pcntl_exec() is not available in this compiler build');
    }

    public static function wifexited(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifexited($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifexited($status);
        }

        throw new \Error('pcntl_wifexited() is not available in this compiler build');
    }

    public static function wexitstatus(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wexitstatus($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wexitstatus($status);
        }

        throw new \Error('pcntl_wexitstatus() is not available in this compiler build');
    }

    public static function wifsignaled(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifsignaled($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifsignaled($status);
        }

        throw new \Error('pcntl_wifsignaled() is not available in this compiler build');
    }

    public static function wifstopped(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifstopped($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifstopped($status);
        }

        throw new \Error('pcntl_wifstopped() is not available in this compiler build');
    }

    public static function wtermsig(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wtermsig($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wtermsig($status);
        }

        throw new \Error('pcntl_wtermsig() is not available in this compiler build');
    }

    public static function wstopsig(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wstopsig($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wstopsig($status);
        }

        throw new \Error('pcntl_wstopsig() is not available in this compiler build');
    }

    public static function hasHandler(int $signo): bool
    {
        return isset(self::$handlers[$signo]);
    }

    public static function markPending(int $signo): void
    {
        self::$pending[] = $signo;
    }

    public static function signal(int $signo, ?Variable $handler): bool
    {
        VmPcntlArg::validateSignal($signo, 'pcntl_signal');
        if (PcntlConstants::isUncatchable($signo)) {
            throw new \ValueError('Cannot catch SIGKILL or SIGSTOP');
        }
        if (null === $handler) {
            unset(self::$handlers[$signo], self::$dispositions[$signo]);

            return self::restoreOsHandler($signo);
        }
        $resolved = $handler->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $disposition = $resolved->toInt();
            if (PcntlConstants::SIG_DFL === $disposition || PcntlConstants::SIG_IGN === $disposition) {
                unset(self::$handlers[$signo]);
                self::$dispositions[$signo] = $disposition;

                return self::installOsDisposition($signo, $disposition);
            }
        }
        unset(self::$dispositions[$signo]);
        if (VmClosureCall::isClosure($resolved)) {
            $stored = new Variable();
            $stored->copyFrom($resolved);
            self::$handlers[$signo] = [
                'kind' => 'closure',
                'closure' => VmClosureCall::resolve($resolved),
                'source' => $stored,
            ];
        } else {
            $stored = new Variable();
            $stored->copyFrom($resolved);
            self::$handlers[$signo] = [
                'kind' => 'callable',
                'callable' => $stored,
            ];
        }

        return self::installOsHandler($signo);
    }

    public static function getHandler(int $signo): Variable
    {
        VmPcntlArg::validateSignal($signo, 'pcntl_signal_get_handler');
        $ret = new Variable();
        if (isset(self::$handlers[$signo])) {
            $handler = self::$handlers[$signo];
            if ('closure' === $handler['kind']) {
                $ret->copyFrom($handler['source']);

                return $ret;
            }
            $ret->copyFrom($handler['callable']);

            return $ret;
        }
        $ret->int(self::$dispositions[$signo] ?? PcntlConstants::SIG_DFL);

        return $ret;
    }

    public static function asyncSignals(?bool $enable): bool
    {
        if (PcntlHostBridge::available() && \function_exists('pcntl_async_signals')) {
            return PcntlHostBridge::asyncSignals($enable);
        }
        if (null === $enable) {
            return self::$asyncSignals;
        }
        self::$asyncSignals = $enable;

        return true;
    }

    public static function sigprocmask(int $mode, array $signals, ?Variable $oldOut): bool
    {
        foreach ($signals as $signo) {
            VmPcntlArg::validateSignal($signo, 'pcntl_sigprocmask');
        }
        $old = [];
        if (PcntlHostBridge::available() && \function_exists('pcntl_sigprocmask')) {
            $ok = PcntlHostBridge::sigprocmask($mode, $signals, $old);
            if (null !== $oldOut) {
                VmPcntlArg::writeSignalList($old, $oldOut);
            }
            self::$blockedSignals = $old;

            return $ok;
        }
        if (PcntlLibcThinAbi::sigprocmaskAvailable()) {
            $ok = PcntlLibcThinAbi::sigprocmask($mode, $signals, $old);
            if (null !== $oldOut) {
                VmPcntlArg::writeSignalList($old, $oldOut);
            }
            self::$blockedSignals = $old;

            return $ok;
        }
        $previous = self::$blockedSignals;
        self::$blockedSignals = self::applyLocalMask($mode, $signals, self::$blockedSignals);
        if (null !== $oldOut) {
            VmPcntlArg::writeSignalList($previous, $oldOut);
        }

        return true;
    }

    /**
     * @param array<string, int>|null $infoOut
     */
    public static function sigtimedwait(array $signals, ?Variable $infoOut, int $seconds, int $nanoseconds): int|false
    {
        foreach ($signals as $signo) {
            VmPcntlArg::validateSignal($signo, 'pcntl_sigtimedwait');
        }
        $info = [];
        if (PcntlHostBridge::available() && \function_exists('pcntl_sigtimedwait')) {
            $rc = PcntlHostBridge::sigtimedwait($signals, $info, $seconds, $nanoseconds);
            if (false !== $rc && null !== $infoOut) {
                self::writeSiginfo($info, $infoOut);
            }

            return $rc;
        }

        throw new \Error('pcntl_sigtimedwait() is not available in this compiler build');
    }

    public static function waitid(int $idtype, int $id, ?Variable $infoOut, int $options): bool
    {
        if (PcntlLibcThinAbi::waitidAvailable()) {
            $info = [];
            $ok = PcntlLibcThinAbi::waitid($idtype, $id, $info, $options);
            if ($ok && null !== $infoOut) {
                self::writeSiginfo($info, $infoOut);
            }

            return $ok;
        }

        throw new \Error('pcntl_waitid() is not available in this compiler build');
    }

    public static function dispatch(Context $context): bool
    {
        if (!self::available()) {
            return false;
        }
        if (PcntlHostBridge::preferred()) {
            PcntlHostBridge::drainHostPending();
        }
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $signo) {
            if (!isset(self::$handlers[$signo])) {
                continue;
            }
            self::invokeHandler($context, $signo, self::$handlers[$signo]);
        }

        return true;
    }

    /**
     * @param array{kind: 'closure', closure: ClosureState, source: Variable}|array{kind: 'callable', callable: Variable} $handler
     */
    private static function invokeHandler(Context $context, int $signo, array $handler): void
    {
        $signoVar = new Variable();
        $signoVar->int($signo);
        if ('closure' === $handler['kind']) {
            VmClosureCall::invoke($context, $handler['closure'], $signoVar);

            return;
        }
        VmCallable::invoke($context, $handler['callable'], $signoVar);
    }

    private static function installOsHandler(int $signo): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::installHandler($signo);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::installHandler($signo);
        }

        return true;
    }

    private static function installOsDisposition(int $signo, int $disposition): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::installDisposition($signo, $disposition);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::installDisposition($signo, $disposition);
        }

        return true;
    }

    private static function restoreOsHandler(int $signo): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::restoreDefault($signo);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::restoreDefault($signo);
        }

        return true;
    }

    /**
     * @param list<int> $signals
     * @param list<int> $current
     *
     * @return list<int>
     */
    private static function applyLocalMask(int $mode, array $signals, array $current): array
    {
        $set = [];
        foreach ($current as $signo) {
            $set[(int) $signo] = true;
        }
        foreach ($signals as $signo) {
            $signo = (int) $signo;
            if (PcntlConstants::SIG_BLOCK === $mode) {
                $set[$signo] = true;
            } elseif (PcntlConstants::SIG_UNBLOCK === $mode) {
                unset($set[$signo]);
            } elseif (PcntlConstants::SIG_SETMASK === $mode) {
                $set = [];
            }
        }
        if (PcntlConstants::SIG_SETMASK === $mode) {
            foreach ($signals as $signo) {
                $set[(int) $signo] = true;
            }
        }

        return \array_keys($set);
    }

    /**
     * @param array<string, int> $info
     */
    private static function writeSiginfo(array $info, Variable $out): void
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $var = new Variable();
            $var->int((int) $value);
            $ht->add((string) $key, $var);
        }
        $out->byRefTarget()->array($ht);
    }
}

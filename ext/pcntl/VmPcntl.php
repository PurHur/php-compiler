<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;

/** VM pcntl_signal()/pcntl_signal_dispatch() (php-src ext/pcntl/pcntl.c; issue #6680). */
final class VmPcntl
{
    /** @var array<int, array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable}> */
    private static array $handlers = [];

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
        if (PcntlConstants::isUncatchable($signo)) {
            throw new \ValueError('Cannot catch SIGKILL or SIGSTOP');
        }
        if (null === $handler) {
            unset(self::$handlers[$signo]);

            return self::restoreOsHandler($signo);
        }
        $resolved = $handler->resolveIndirect();
        if (VmClosureCall::isClosure($resolved)) {
            self::$handlers[$signo] = [
                'kind' => 'closure',
                'closure' => VmClosureCall::resolve($resolved),
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
     * @param array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable} $handler
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
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** inotify_read() — read queued events (php-src ext/inotify/inotify.c; #6410). */
final class inotify_read extends Internal
{
    public function __construct()
    {
        parent::__construct('inotify_read');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'inotify_read() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $stream = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'inotify_read',
            1
        );
        $events = VmInotify::read($stream);
        if (false === $events) {
            self::warn($frame, 'inotify_read(): Unable to read inotify events');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmInotify::eventsToHashTable($events));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('inotify_read() is not implemented for JIT in this compiler build (issue #6410)');
    }

    private static function warn(Frame $frame, string $message): void
    {
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING
            );
        }
    }
}

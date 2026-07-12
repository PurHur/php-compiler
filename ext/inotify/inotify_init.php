<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** inotify_init() — create inotify instance stream (php-src ext/inotify/inotify.c; #6410). */
final class inotify_init extends Internal
{
    public function __construct()
    {
        parent::__construct('inotify_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'inotify_init() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmInotify::available()) {
            self::warn($frame, 'inotify_init(): Unable to initialize inotify');

            $frame->returnVar->bool(false);

            return;
        }
        $handle = VmInotify::init();
        if (false === $handle) {
            self::warn($frame, 'inotify_init(): Unable to initialize inotify');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('inotify_init() is not implemented for JIT in this compiler build (issue #6410)');
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** inotify_add_watch() — register path watch (php-src ext/inotify/inotify.c; #6410). */
final class inotify_add_watch extends Internal
{
    public function __construct()
    {
        parent::__construct('inotify_add_watch');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'inotify_add_watch() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $stream = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'inotify_add_watch',
            1
        );
        $pathname = VmString::coercePathBuiltinArg(
            $frame->calledArgs[1],
            'inotify_add_watch',
            1,
            'pathname'
        );
        $mask = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[2]->resolveIndirect(),
            'inotify_add_watch',
            3,
            'mask'
        );
        $wd = VmInotify::addWatch($stream, $pathname, $mask);
        if (false === $wd) {
            self::warn($frame, 'inotify_add_watch(): Unable to watch pathname');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($wd);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('inotify_add_watch() is not implemented for JIT in this compiler build (issue #6410)');
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

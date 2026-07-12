<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** inotify_rm_watch() — remove watch descriptor (php-src ext/inotify/inotify.c; #6410). */
final class inotify_rm_watch extends Internal
{
    public function __construct()
    {
        parent::__construct('inotify_rm_watch');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'inotify_rm_watch() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $stream = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'inotify_rm_watch',
            1
        );
        $wd = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'inotify_rm_watch',
            2,
            'wd'
        );
        if (!VmInotify::rmWatch($stream, $wd)) {
            self::warn($frame, 'inotify_rm_watch(): Unable to remove watch');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('inotify_rm_watch() is not implemented for JIT in this compiler build (issue #6410)');
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * rmdir() — VM via VmFs; JIT/AOT via RmdirJitHelper PHP (#15481, #23454).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(rmdir) with optional stream context.
 * Stream $context is accepted and type-checked; wrapper contexts that change rmdir
 * semantics are not yet applied (same accept/ignore shape as unlink/mkdir).
 */
final class rmdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rmdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'rmdir() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'rmdir() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (isset($frame->calledArgs[1])) {
            VmStreamContext::validateOptionalContextArg($frame->calledArgs[1], 'rmdir', 2);
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'rmdir', 0, 'directory', $frame);
        $ok = VmFs::rmdir($path);
        if (!$ok) {
            if (VmStatPath::isDir($path) && VmFs::isDirNonempty($path)) {
                VmFilestatFailure::warnRmdirNotEmpty($frame, $path);
            } else {
                VmFilestatFailure::warnRmdirMissing($frame, $path);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'rmdir() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'rmdir() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            JitStreamContextOptionalArg::validate($context, $args[1], 'rmdir', 2);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'rmdir', 0, 'directory');

        return JitRmdir::invoke($context, $path);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** disk_total_space() — VM via VmFsDiskNative (statvfs FFI or VmFsDiskPure); JIT/AOT via JitStat (php-src filestat.c, #8989). */
final class disk_total_space extends Internal
{
    public function __construct()
    {
        parent::__construct('disk_total_space');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('disk_total_space() accepts at most one argument in this compiler build');
        }
        $path = null;
        if ($argc >= 1) {
            $resolved = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL === $resolved->type) {
                if (InternalStrictArg::isCallerStrict($frame)) {
                    throw new \TypeError(
                        'disk_total_space(): Argument #1 ($directory) must be of type string, null given'
                    );
                }
                if (null === $frame->returnVar) {
                    return;
                }
                // php-src filestat.c — null directory returns false without warning (#12619, #12788).
                $frame->returnVar->bool(false);

                return;
            }
            $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'disk_total_space', 0, 'directory');
        }
        $result = VmFs::diskTotalSpace($path);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            VmFilestatFailure::warnNoSuchFile($frame, 'disk_total_space');
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->float($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('disk_total_space() accepts at most one argument in this compiler build');
        }
        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0] ?? null, 'disk_total_space', false);
    }
}

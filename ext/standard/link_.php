<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** link() — VM via VmFs; JIT/AOT via libc linkat(2) (issue #3589). */
final class link_ extends Internal
{
    public function __construct()
    {
        parent::__construct('link');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'link() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'link', 'target', 0, $frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'link', 'link', 1, $frame);
        $target = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'link', 0, 'target');
        $linkPath = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'link', 1, 'link');
        $ok = VmFs::hardLink($target, $linkPath);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'link');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'link() expects exactly 2 arguments, '.\count($args).' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $target = JitStringBuiltinArg::lower($context, $args[0], 'link', 0, 'target');
        $linkPath = JitStringBuiltinArg::lower($context, $args[1], 'link', 1, 'link');

        return JitLink::invoke($context, $target, $linkPath);
    }
}

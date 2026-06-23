<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** copy() — VM via VmFs; JIT/AOT via __compiler_copy (native fread/fwrite). */
final class copy_ extends Internal
{
    public function __construct()
    {
        parent::__construct('copy');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('copy() requires exactly two arguments in this compiler build');
        }
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'copy', 0, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'copy', 1, 'to');
        $ok = VmFs::copy($from, $to);
        if (!$ok) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'copy', $from);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('copy() requires exactly two arguments in this compiler build');
        }
        $from = JitStringBuiltinArg::lower($context, $args[0], 'copy', 0, 'from');
        $to = JitStringBuiltinArg::lower($context, $args[1], 'copy', 1, 'to');

        return JitCopy::invoke($context, $from, $to);
    }
}

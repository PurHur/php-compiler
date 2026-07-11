<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** convert_uuencode() — Unix-to-Unix encoding (VmString; JIT via ConvertUuJitHelper, #13227). */
final class convert_uuencode extends Internal
{
    public function __construct()
    {
        parent::__construct('convert_uuencode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('convert_uuencode() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'convert_uuencode', 0, 'string');
        $frame->returnVar->string(VmString::convert_uuencode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('convert_uuencode() requires exactly one argument in this compiler build');
        }

        return JitConvertUuencode::encode(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'convert_uuencode', 0, 'string')
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** convert_uudecode() — decode uuencoded string (php-src ext/standard/uuencode.c). */
final class convert_uudecode extends Internal
{
    private const MSG_INVALID = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';

    public function __construct()
    {
        parent::__construct('convert_uudecode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('convert_uudecode() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'convert_uudecode', 'string');
        $result = VmString::convert_uudecode($data);
        if (false === $result) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::MSG_INVALID,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('convert_uudecode() requires exactly one argument in this compiler build');
        }

        return JitConvertUudecode::decode(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'convert_uudecode', 0, 'string')
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** convert_uuencode() — Unix-to-Unix encoding (VmString; JIT/AOT via ConvertUuJitHelper+VmConvertUu, #30811). */
final class convert_uuencode extends Internal
{
    public function __construct()
    {
        parent::__construct('convert_uuencode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/uuencode.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'convert_uuencode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $data = self::vmStringArg($frame, 0, 'string');
        $frame->returnVar->string(VmString::convert_uuencode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'convert_uuencode', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        return JitConvertUuencode::encode(
            $context,
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'convert_uuencode', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'convert_uuencode',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'convert_uuencode',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'convert_uuencode',
            $argIndex,
            $paramName
        );
    }
}

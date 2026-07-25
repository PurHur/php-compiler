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
        // php-src ext/standard/uuencode.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'convert_uudecode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $data = self::vmStringArg($frame, 0, 'string');
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
        if (!$this->requireExactJitArgCount($context, $args, 'convert_uudecode', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        return JitConvertUudecode::decode(
            $context,
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    /** Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21420). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'convert_uudecode', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'convert_uudecode',
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
                'convert_uudecode',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'convert_uudecode',
            $argIndex,
            $paramName
        );
    }
}

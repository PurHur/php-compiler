<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * json_decode()/json_validate() $json operand — typed string on 8.4+ forward profile (#18852, ext/json/json.stub.php).
 *
 * General Z_PARAM_STR builtins coerce null outside caller strict_types (#19161); json_* typed $json rejects null
 * on PHP_COMPILER_PROFILE=8.4 like Zend 8.4+.
 */
final class JsonStringOperandArg
{
    public static function vmJson(Frame $frame, string $function, int $argIndex = 0): string
    {
        if (CompilerVersion::jsonStringOperandRequiresStrictType()) {
            return VmString::requireStringBuiltinArg(
                $frame->calledArgs[$argIndex],
                $function,
                $argIndex,
                'json'
            );
        }

        return InternalStrictArg::resolveCoercibleStringArg($frame, $argIndex, $function, 'json');
    }

    public static function jitJson(Context $context, JITVariable $arg, string $function, int $argIndex = 0): Value
    {
        if (CompilerVersion::jsonStringOperandRequiresStrictType()) {
            return JitStringBuiltinArg::lowerRequiredString($context, $arg, $function, $argIndex, 'json');
        }

        return JitStringBuiltinArg::lowerCoercible($context, $arg, $function, $argIndex, 'json');
    }
}

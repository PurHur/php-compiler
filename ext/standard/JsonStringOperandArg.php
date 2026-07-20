<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * json_decode()/json_validate() $json operand — Z_PARAM_STR soft-null (DEP+coerce) on 8.4 (#21223).
 *
 * php-src ref: ext/json/json.c / json.stub.php — typed `string $json` still uses Z_PARAM_STR, which
 * deprecates null and coerces to "" (not TypeError) outside caller strict_types.
 */
final class JsonStringOperandArg
{
    public static function vmJson(Frame $frame, string $function, int $argIndex = 0): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, $function, 'json')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            $function,
            $argIndex,
            'json'
        );
    }

    public static function jitJson(Context $context, JITVariable $arg, string $function, int $argIndex = 0): Value
    {
        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, $function, $argIndex, 'json');
    }
}

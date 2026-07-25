<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringBin2hex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * bin2hex() for string arguments (subset of PHP).
 *
 * VM: {@see VmString::bin2hex()}; JIT/AOT: {@see StringBin2hex} + {@see Bin2hexJitHelper}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex) / Z_PARAM_STR
 */
final class bin2hex extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'bin2hex', 1);
        $data = self::vmStringArg($frame);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($data): void {
            $ret->string(VmString::bin2hex($data));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'bin2hex', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        StringBin2hex::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_bin2hex'),
            self::jitStringArg($context, $args[0])
        );
    }

    /**
     * Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src string.c / #21181).
     * Reverts mistaken #20154 forward-profile TypeError; strict_types still TypeErrors.
     */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'bin2hex', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'bin2hex',
            0,
            'string'
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'bin2hex',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'bin2hex',
            0,
            'string'
        );
    }
}

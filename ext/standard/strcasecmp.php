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
use PHPCompiler\JIT\Builtin\StringStrcasecmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strcasecmp() for two strings (ASCII case fold subset; JIT via CaseCompareJitHelper PHP #15225).
 */
final class strcasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('strcasecmp() requires exactly two arguments');
        }
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strcasecmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('strcasecmp() requires exactly two arguments');
        }
        StringStrcasecmp::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strcasecmp', 0, 'string1'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'strcasecmp', 1, 'string2'));
        $fn = $context->lookupFunction('strcasecmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strcasecmp', $paramName)->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'strcasecmp',
            $argIndex,
            $paramName
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringUniqid;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * uniqid() — time-based unique string (VmString; JIT/AOT via StringUniqid + UniqidJitHelper, #2219 #5233).
 *
 * Z_PARAM_STR $prefix: null TypeError on PHP_COMPILER_PROFILE=8.4 (#20138, re-#18788).
 */
final class uniqid extends Internal
{
    public function __construct()
    {
        parent::__construct('uniqid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException('uniqid() accepts at most two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $prefix = '';
        $moreEntropy = false;
        if ($argc >= 1) {
            // Z_PARAM_STR — null TypeError on PROFILE=8.4 (php-src uniqid.c; #20138).
            $prefix = VmString::coerceZparamStrBuiltinArg(
                $frame->calledArgs[0],
                'uniqid',
                0,
                'prefix'
            );
        }
        if (2 === $argc) {
            $moreEntropy = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'uniqid',
                2,
                'more_entropy'
            );
        }
        $frame->returnVar->string(VmString::uniqid($prefix, $moreEntropy));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 2) {
            throw new \LogicException('uniqid() accepts at most two arguments in this compiler build');
        }
        $prefix = $context->builder->load($context->constantStringFromString(''));
        if (isset($args[0])) {
            $prefix = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'uniqid', 0, 'prefix');
        }
        $moreEntropy = $context->constantFromBool(false);
        if (isset($args[1])) {
            $moreEntropy = JitBoolArg::lower(
                $context,
                $args[1],
                'uniqid(): Argument #2 ($more_entropy)'
            );
        }

        StringUniqid::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_uniqid'),
            $prefix,
            $moreEntropy
        );
    }
}

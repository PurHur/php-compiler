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
 * Soft-null $prefix on forward profile — Zend 8.4 deprecate+coerce (#21280; reverts #20138 TypeError).
 */
final class uniqid extends Internal
{
    public function __construct()
    {
        parent::__construct('uniqid');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/uniqid.c — ArgumentCountError (#21964).
        $this->requireAtMostArgCount($frame, 'uniqid', 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $prefix = '';
        $moreEntropy = false;
        if ($argc >= 1) {
            // Soft-null — Zend 8.4 deprecate+coerce (php-src uniqid.c; #21280).
            $prefix = VmString::trimFamilyStringArgForFrame(
                $frame,
                0,
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
        if (!$this->requireAtMostJitArgCount($context, $args, 'uniqid', 2)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        $prefix = $context->builder->load($context->constantStringFromString(''));
        if (isset($args[0])) {
            if ($context->callerStrictTypes) {
                $prefix = JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[0],
                    'uniqid',
                    0,
                    'prefix'
                );
            } else {
                $prefix = JitStringBuiltinArg::lowerTrimFamilyString(
                    $context,
                    $args[0],
                    'uniqid',
                    0,
                    'prefix'
                );
            }
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

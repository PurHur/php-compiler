<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * sodium_hex2bin() — hex to binary (php-src ext/sodium/libsodium.c; #3438, #24772, #35357).
 *
 * JIT/AOT: NestedJIT via {@see SodiumHex2binJitHelper} / {@see JitSodium::invokeHex2bin} — no new C.
 * Compile-time `$ignore` is peeled at the call site (NestedJIT cannot dim-fetch two strings).
 */
final class sodium_hex2bin extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_hex2bin');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least 1 argument, %d given',
                $this->getName(),
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 2 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'sodium_hex2bin', 0, 'string');
        $ignore = '';
        if ($argc >= 2) {
            $ignore = VmString::trimFamilyStringArgForFrame($frame, 1, 'sodium_hex2bin', 1, 'ignore');
        }
        $result = VmSodium::hex2bin($string, $ignore);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (!$this->requireAtLeastJitArgCount($context, $args, $this->getName(), 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (!$this->requireAtMostJitArgCount($context, $args, $this->getName(), 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        $stringLit = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $stringLit = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        }
        $ignoreLit = '';
        if ($argc >= 2 && JITVariable::TYPE_VALUE !== $args[1]->type) {
            $ignoreLit = $args[1]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[1]);
            if (null === $ignoreLit) {
                $ignoreLit = null;
            }
        } elseif ($argc < 2) {
            $ignoreLit = '';
        } else {
            $ignoreLit = null;
        }
        if (null !== $stringLit && null !== $ignoreLit) {
            try {
                $folded = VmSodium::hex2bin($stringLit, $ignoreLit);

                return $context->builder->load($context->constantStringFromString($folded));
            } catch (\SodiumException) {
                // Invalid constant hex must throw at run time, not abort compile.
            }
        }

        $string = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                $this->getName(),
                0,
                'string'
            )
            : JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                $this->getName(),
                0,
                'string'
            );

        // Peel compile-time ignore at the call site — NestedJIT two-string dim-fetch is broken.
        if (null !== $ignoreLit && '' !== $ignoreLit) {
            $len = \strlen($ignoreLit);
            for ($i = 0; $i < $len; ++$i) {
                $ch = $context->builder->load($context->constantStringFromString($ignoreLit[$i]));
                $string = JitSodium::invokeStripChar($context, $string, $ch);
            }

            return JitSodium::invokeDecode($context, $string);
        }

        if (null === $ignoreLit) {
            // Runtime ignore: peel chars via single-string NestedJIT helpers (two-string
            // dim-fetch is broken under NestedJIT — #35357).
            $ignore = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[1],
                    $this->getName(),
                    1,
                    'ignore'
                )
                : JitStringBuiltinArg::lowerTrimFamilyString(
                    $context,
                    $args[1],
                    $this->getName(),
                    1,
                    'ignore'
                );
            for ($n = 0; $n < 16; ++$n) {
                $byte = JitSodium::invokeIgnoreByte($context, $ignore);
                $string = JitSodium::invokeStripByte($context, $string, $byte);
                $ignore = JitSodium::invokeIgnoreRest($context, $ignore);
            }

            return JitSodium::invokeDecode($context, $string);
        }

        return JitSodium::invokeDecode($context, $string);
    }
}

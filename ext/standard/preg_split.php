<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** preg_split() — VM via VmPreg; JIT/AOT via __compiler_preg_split (issue #1178, #3639). */
final class preg_split extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(
                'preg_split() expects 2 to 4 arguments in this compiler build'
            );
        }
        // Soft-null $pattern on 8.4 — Zend DEP+empty-pattern warn+false (#21479, reverts #20226 TypeError).
        // $subject soft-null: E_DEPRECATED + '' on 8.4 (php-src php_pcre.c / #21318, re-#21198).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'preg_split', 0, 'pattern');
        $subject = VmString::trimFamilyStringArgForFrame($frame, 1, 'preg_split', 1, 'subject');
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_split', $pattern);
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            // Z_PARAM_LONG $limit — soft-null DEP+coerce on 8.4 (php_pcre.c; #21655).
            $limit = VmMath::parseChrCodepointForFrame($frame, 2, 'preg_split', 3, 'limit');
        }
        if (4 === $argc) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'preg_split', 4, 'flags');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parts = VmPreg::pregSplit($pattern, $subject, $limit, $flags);
        if (false === $parts) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmPreg::splitPartsToHashTable($parts, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(
                'preg_split() expects 2 to 4 arguments in this compiler build'
            );
        }
        $limitLit = null;
        $flagsLit = null;
        if ($argc >= 3 && null !== $args[2]->compileTimeInt) {
            $limitLit = (int) $args[2]->compileTimeInt;
        }
        if (4 === $argc && null !== $args[3]->compileTimeInt) {
            $flagsLit = (int) $args[3]->compileTimeInt;
        }
        // Literal fold — thin AOT NestedJIT split HT fill is unreliable (#27208 / peer #27181).
        if (
            (2 === $argc || null !== $limitLit)
            && (4 !== $argc || null !== $flagsLit)
        ) {
            $folded = JitPregSplitCompileTime::tryFold(
                $context,
                $args[0],
                $args[1],
                $limitLit,
                $flagsLit
            );
            if (null !== $folded) {
                return $folded;
            }
        }
        // Runtime ABI (thin: replaceFindNext + LLVM HT fill; embed: splitArgv HT).
        $limit = $context->getTypeFromString('int64')->constInt(-1, true);
        $flags = $context->getTypeFromString('int64')->constInt(0, false);
        if ($argc >= 3) {
            // Soft-null DEP+coerce on 8.4 (php_pcre.c Z_PARAM_LONG; #21655).
            $limit = JitChr::lowerZParamLongArg($context, $args[2], 'preg_split', 3, 'limit');
        }
        if (4 === $argc) {
            $flags = JitIntdiv::lowerIntBuiltinArg($context, $args[3], 'preg_split', 4, 'flags');
        }

        // Soft-null $pattern on 8.4 — Zend DEP+empty-pattern warn+false (#21479, reverts #20226 TypeError).
        // $subject soft-null DEP+coerce on 8.4 (#21318; php-src php_pcre.c).
        if ($context->callerStrictTypes) {
            $pattern = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'preg_split', 0, 'pattern');
            $subject = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'preg_split', 1, 'subject');
        } else {
            $pattern = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'preg_split', 0, 'pattern');
            $subject = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'preg_split', 1, 'subject');
        }

        return JitPregSplit::invoke(
            $context,
            $pattern,
            $subject,
            $limit,
            $flags
        );
    }
}

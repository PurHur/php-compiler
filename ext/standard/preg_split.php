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
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_split() pattern', 0);
        $subject = VmReflection::stringArg($frame->calledArgs[1], 'preg_split() subject', 1);
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_split', $pattern);
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            $limit = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'preg_split', 3, 'limit');
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
        $patternLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $subjectLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $canConstexpr = null !== $patternLit && null !== $subjectLit;
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            $limitCt = self::compileTimeLimit($context, $args[2]);
            if (null === $limitCt) {
                $canConstexpr = false;
            } else {
                $limit = $limitCt;
            }
        }
        if (4 === $argc) {
            $flagsCt = self::compileTimeLimit($context, $args[3]);
            if (null === $flagsCt) {
                $canConstexpr = false;
            } else {
                $flags = $flagsCt;
            }
        }
        if ($canConstexpr) {
            $parts = VmPreg::pregSplit($patternLit, $subjectLit, $limit, $flags);
            if (false === $parts) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            $ht = VmPreg::splitPartsToHashTable($parts, $flags);

            return $context->constantArrayFromVmHashTable(
                'preg_split_'.md5($patternLit."\0".$subjectLit."\0".$limit."\0".$flags),
                $ht
            );
        }
        $limit = $context->getTypeFromString('int64')->constInt(-1, true);
        $flags = $context->getTypeFromString('int64')->constInt(0, false);
        if ($argc >= 3) {
            $limit = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'preg_split', 3, 'limit');
        }
        if (4 === $argc) {
            $flags = JitIntdiv::lowerIntBuiltinArg($context, $args[3], 'preg_split', 4, 'flags');
        }

        return JitPregSplit::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'preg_split', 0, 'pattern'),
            JitStringBuiltinArg::lower($context, $args[1], 'preg_split', 1, 'subject'),
            $limit,
            $flags
        );
    }

    private static function compileTimeLimit(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return VmMath::floatToZendLong((float) $const->constDouble());
            }
        }

        return null;
    }
}

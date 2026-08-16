<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** preg_grep() — VM via VmPreg; JIT/AOT via JitPregGrep (issue #1180). */
final class preg_grep extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_grep');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('preg_grep() requires two or three arguments in this compiler build');
        }
        // Soft-null $pattern on 8.4 — Zend DEP+empty-pattern warn+false (#21479, reverts #20226; ext/pcre/php_pcre.c).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'preg_grep', 0, 'pattern');
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_grep', $pattern);
        // php-src Z_PARAM_ARRAY — TypeError on null (not LogicException); #22679.
        $src = VmArray::requireArrayParam($frame->calledArgs[1], 'preg_grep', 2, 'array');
        $invert = false;
        if (3 === $argc) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31385).
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'preg_grep',
                3,
                'flags'
            );
            // php-src php_pcre.c PHP_FUNCTION(preg_grep) — mask PREG_GREP_INVERT; unknown bits ignored (#27946).
            $invert = 0 !== ($flags & StdlibConstants::PREG_GREP_INVERT);
        }
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $value = $value->resolveIndirect();
            // php-src php_preg_grep: convert_to_string per element; keep original zval (#27164).
            $match = VmPreg::pregMatch($pattern, self::haystackValueAsString($value, $frame));
            if (false === $match) {
                if (StdlibConstants::PREG_BAD_UTF8_ERROR === VmPreg::lastError()) {
                    continue;
                }
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $keep = 1 === $match;
            if ($invert) {
                $keep = !$keep;
            }
            if ($keep) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($out);
        }
    }

    /**
     * Zend php_preg_grep: haystack elements convert to string for matching (#5639, #27164).
     */
    private static function haystackValueAsString(Variable $value, Frame $frame): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
            throw new \Error(
                'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
            );
        }

        return $value->toString(null, $frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('preg_grep() requires two or three arguments in this compiler build');
        }
        // Soft-null outside strict_types; strict → TypeError (#31385 / peer token_get_all #31361).
        if (3 === $argc
            && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'preg_grep', 3, 'flags');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'preg_grep_null_flags_te_cont');

            return HashTableHelper::emptyVariable($context)->value;
        }

        $invert = $context->constantFromBool(false);
        if (3 === $argc) {
            // Z_PARAM_LONG — strict TypeError / soft-null DEP+coerce (#31385).
            $flags = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[2],
                'preg_grep',
                3,
                'flags'
            );
            $i64 = $context->getTypeFromString('int64');
            $masked = $context->builder->and($flags, $i64->constInt(StdlibConstants::PREG_GREP_INVERT, true));
            $invert = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $masked,
                $i64->constInt(0, true)
            );
        }

        // Soft-null $pattern on 8.4 — Zend DEP+empty-pattern warn+false (#21479, reverts #20226).
        $pattern = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'preg_grep', 0, 'pattern')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'preg_grep', 0, 'pattern');

        // php-src Z_PARAM_ARRAY — TypeError on null (#22679); avoid loadHashTable LogicException.
        JitArrayElem::requireArrayParam($context, $args[1], 'preg_grep', 2, 'array');
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return HashTableHelper::emptyVariable($context)->value;
        }

        return JitPregGrep::invoke(
            $context,
            $pattern,
            $args[1],
            $invert
        );
    }
}

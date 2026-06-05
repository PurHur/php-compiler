<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSubstrCompare;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * substr_compare() — compare haystack slice to needle (subset of PHP; issue #2400).
 * JIT lowers via {@see StringSubstrCompareJit} (VmString parity; no phpc_substr_compare.c).
 */
final class substr_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('substr_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('substr_compare() accepts three to five arguments in this compiler build');
        }
        $haystack = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'substr_compare', 0, 'haystack');
        $needle = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'substr_compare', 1, 'needle');
        $offset = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offset->type) {
            throw new \TypeError('substr_compare(): Argument #3 ($offset) must be of type int, '
                .EnumCaseSupport::typeNameForVariable($offset).' given');
        }
        $length = null;
        if ($argc >= 4) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL === $lengthArg->type) {
                $length = null;
            } elseif (Variable::TYPE_INTEGER !== $lengthArg->type) {
                throw new \LogicException('substr_compare() argument #4 must be an integer in this compiler build');
            } else {
                $length = $lengthArg->toInt();
            }
        }
        $caseInsensitive = false;
        if (5 === $argc) {
            $ci = $frame->calledArgs[4]->resolveIndirect();
            $caseInsensitive = $ci->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::substr_compare(
            $haystack,
            $needle,
            $offset->toInt(),
            $length,
            $caseInsensitive
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringSubstrCompare::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('substr_compare() accepts three to five arguments in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_compare() offset must be an integer in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $lengthVal = $i64->constInt(-1, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type) {
                $lengthVal = $this->jitLong($context, $args[3], 'substr_compare() length');
            } elseif (JITVariable::TYPE_VALUE === $args[3]->type) {
                if (!$args[3]->isNullConstant) {
                    throw new \LogicException('substr_compare() length must be an integer or literal null in this compiler build');
                }
                $lengthVal = $i64->constInt(-1, false);
            } else {
                throw new \LogicException('substr_compare() length must be an integer or null in this compiler build');
            }
        }
        $ci = $i32->constInt(0, false);
        if (5 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[4]->type) {
                throw new \LogicException('substr_compare() case_insensitive must be a boolean in this compiler build');
            }
            $ci = $context->builder->zExt(
                $this->jitBool($context, $args[4], 'substr_compare() case_insensitive'),
                $i32
            );
        }
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[0], 'substr_compare', 0, 'haystack'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[1], 'substr_compare', 1, 'needle'));
        $offset = $this->jitLong($context, $args[2], 'substr_compare() offset');
        $fn = $context->lookupFunction('substr_compare');
        $raw = $context->builder->call($fn, $p0, $p1, $offset, $lengthVal, $ci);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}

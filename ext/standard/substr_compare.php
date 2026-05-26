<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSubstrCompare;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * substr_compare() — compare haystack slice to needle (subset of PHP; issue #2400).
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
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        $offset = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $haystack->type
            || Variable::TYPE_STRING !== $needle->type
            || Variable::TYPE_INTEGER !== $offset->type) {
            throw new \LogicException('substr_compare() requires two strings and an integer offset in this compiler build');
        }
        $length = null;
        if ($argc >= 4) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lengthArg->type) {
                throw new \LogicException('substr_compare() argument #4 must be an integer in this compiler build');
            }
            $length = $lengthArg->toInt();
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
            $haystack->toString(),
            $needle->toString(),
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
            if (JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
                throw new \LogicException('substr_compare() length must be an integer in this compiler build');
            }
            $lengthVal = $this->jitLong($context, $args[3], 'substr_compare() length');
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
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'substr_compare() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'substr_compare() argument #2'));
        $offset = $this->jitLong($context, $args[2], 'substr_compare() offset');
        $fn = $context->lookupFunction('substr_compare');
        $raw = $context->builder->call($fn, $p0, $p1, $offset, $lengthVal, $ci);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}

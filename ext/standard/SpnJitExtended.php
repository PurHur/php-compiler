<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrspn;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @mixin Internal */
trait SpnJitExtended
{
    /**
     * @param list<JITVariable> $args
     */
    protected function callSpnExtended(Context $context, array $args, bool $isStrspn, string $name): Value
    {
        $argc = \count($args);
        if ($argc >= 3 && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException("{$name}() offset must be an integer in this compiler build");
        }
        if (4 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
            throw new \LogicException("{$name}() length must be an integer in this compiler build");
        }

        StringStrspn::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $strVal = $this->jitString($context, $args[0], "{$name}() argument #1");
        $maskVal = $this->jitString($context, $args[1], "{$name}() argument #2");
        $strLen = $context->builder->load($context->builder->structGep($strVal, $map['length']));
        $maskLen = $context->builder->load($context->builder->structGep($maskVal, $map['length']));
        $strData = $this->stringDataPtr($context, $strVal);
        $maskData = $this->stringDataPtr($context, $maskVal);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $offset = $argc >= 3
            ? $this->jitLong($context, $args[2], "{$name}() offset")
            : $i64->constInt(0, false);
        $length = 4 === $argc
            ? $this->jitLong($context, $args[3], "{$name}() length")
            : $i64->constInt(0, false);
        $lenIsNull = $i32->constInt(4 === $argc ? 0 : 1, false);
        $mode = $i32->constInt($isStrspn ? 1 : 0, false);
        $fn = $context->lookupFunction('phpc_strspn_ex');

        return $context->builder->call(
            $fn,
            $strData,
            $context->builder->truncOrBitCast($strLen, $sizeT),
            $maskData,
            $context->builder->truncOrBitCast($maskLen, $sizeT),
            $offset,
            $length,
            $lenIsNull,
            $mode
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomNodeIsConnectedRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::$isConnected (#19653). */
final class JitDomNodeIsConnected
{
    public static function isDomNodeIsConnected(string $classLc, string $propLc): bool
    {
        if (!\PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()) {
            return false;
        }
        if (!str_starts_with(strtolower($classLc), 'dom')) {
            return false;
        }

        return 'isconnected' === strtolower($propLc);
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        DomNodeIsConnectedRuntime::ensureLinked($context);
        $flag = $context->builder->call(
            $context->lookupFunction(DomNodeIsConnectedRuntime::ABI_NAME),
            $obj
        );
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $destPtr,
            $context->builder->icmp(Builder::INT_NE, $flag, $i64->constInt(0, false))
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $destPtr)
        );
    }
}

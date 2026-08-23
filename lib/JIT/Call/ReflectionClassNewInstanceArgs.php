<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::newInstanceArgs(?array $args = []) — JIT/AOT (#34090).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Expands a packed
 * ctor-arg array (compile-time {@see Variable::$nextFreeElement} from INIT_ARRAY)
 * then reuses {@see ReflectionClassNewInstance} allocate + `__construct` dispatch.
 *
 * php-src: zim_ReflectionClass_newInstanceArgs
 */
final class ReflectionClassNewInstanceArgs implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_newInstanceArgs — optional array $args (at most 1);
        // $args[0] is $this (#30923)
        if ([] === $args) {
            throw new \LogicException('ReflectionClass::newInstanceArgs() requires an object receiver');
        }
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'ReflectionClass::newInstanceArgs',
            0,
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $userArgCount = \count($args) - 1;

        $ctorArgs = [];
        if (1 === $userArgCount) {
            $ctorArgs = self::expandPackedCtorArgs($context, $args[1]);
        }

        return (new ReflectionClassNewInstance())->call($context, $args[0], ...$ctorArgs);
    }

    /**
     * @return list<Variable>
     */
    private static function expandPackedCtorArgs(Context $context, Variable $argsVar): array
    {
        if (
            Variable::TYPE_HASHTABLE !== $argsVar->type
            && Variable::TYPE_VALUE !== $argsVar->type
            && !JitValueBox::isValueOperand($argsVar)
        ) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionClass::newInstanceArgs(): Argument #1 ($args) must be of type array, %s given',
                    JitOperandTypeLabel::givenLabel($context, $argsVar)
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_nia_typeerror_cont');

            return [];
        }

        if ($argsVar->compileTimeEmptyArrayLiteral || 0 === $argsVar->nextFreeElement) {
            return [];
        }

        $ht = Variable::TYPE_HASHTABLE === $argsVar->type
            ? HashTableHelper::loadHashtablePointer($context, $argsVar)
            : HashTableHelper::ensureHashtablePointer($context, $argsVar);

        $n = $argsVar->nextFreeElement;
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $entry = HashTableReadLlvm::listEntryPointer(
                $context,
                $ht,
                $context->constantFromInteger($i, 'int64')
            );
            // HT elements are value-boxes; typed __construct(int) + TYPE_VALUE hits
            // TypedParamCoerce PHI verify failures (same as newInstance($var)). Promote
            // with __value__readLong so the arg shape matches literal newInstance(5).
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $entry
            );
            $out[] = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $longVal
            );
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getTrace() — JIT/AOT (#27333).
 *
 * php-src: Zend/zend_exceptions.c — Exception::getTrace
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetTrace}
 *
 * Thin AOT seeds PROP_TRACE on #[\SensitiveParameter] throws
 * ({@see \PHPCompiler\JIT\Builtin\ExceptionThrowToStringSeed}). Without a seed,
 * return an empty array — never ExternalMethod null (#579).
 */
final class ExceptionGetTrace implements Call
{
    private static int $seq = 0;

    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getTrace() requires an object receiver');
        }
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getTrace() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_gettrace_argc_cont');
            $emptyHt = HashTableHelper::alloc($context);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);

            return self::writeHt($context, $ptr, $emptyHt);
        }
        $context->type->object->lookup(SensitiveParamSupport::CLASS_NAME);
        GetClassRuntime::ensureLinked($context);

        $tag = 'egt'.(string) (++self::$seq);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $decl = self::declaringClass($context);
        $emptyHt = HashTableHelper::alloc($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        try {
            $cid = $context->type->object->lookup($decl);
        } catch (\Throwable) {
            return self::writeHt($context, $ptr, $emptyHt);
        }
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_TRACE)) {
            return self::writeHt($context, $ptr, $emptyHt);
        }

        $prop = $context->type->object->propertyFetch($obj, $decl, ExceptionSupport::PROP_TRACE);
        $htPtr = $context->helper->loadValue($prop);
        $nullPtr = $context->getTypeFromString('void*')->constNull();
        $asVoid = $context->builder->pointerCast($htPtr, $context->getTypeFromString('void*'));
        $nonNull = $context->builder->icmp(Builder::INT_NE, $asVoid, $nullPtr);
        $useBb = BasicBlockHelper::append($context, 'exc_gt_use_'.$tag);
        $defBb = BasicBlockHelper::append($context, 'exc_gt_def_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'exc_gt_done_'.$tag);
        $context->builder->branchIf($nonNull, $useBb, $defBb);

        $context->builder->positionAtEnd($useBb);
        $context->refcount->addref($htPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $htPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defBb);
        $context->refcount->addref($emptyHt);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $emptyHt
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function writeHt(Context $context, Value $ptr, Value $ht): Value
    {
        $context->refcount->addref($ht);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function declaringClass(Context $context): string
    {
        foreach (['Exception', 'Error'] as $candidate) {
            try {
                $cid = $context->type->object->lookup($candidate);
            } catch (\Throwable) {
                continue;
            }
            if ($context->type->object->hasProperty($cid, ExceptionSupport::PROP_TRACE)) {
                return $candidate;
            }
        }

        return 'Exception';
    }
}

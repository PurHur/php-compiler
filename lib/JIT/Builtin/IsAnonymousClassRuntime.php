<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/** JIT/AOT link for isAnonymousClass() (#19969). */
final class IsAnonymousClassRuntime
{
    private const ABI = '__phpc_is_anonymous_class';

    private const HELPER_PATH = '/ext/reflection/IsAnonymousClassJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\reflection\\IsAnonymousClassJitHelper::probeClassId';

    /** @var list<string> */
    private const COMPILED = [self::HELPER];

    public static function invoke(Context $context, Value $classId): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $classId = $context->builder->zext($classId, $i64);

        return $context->builder->call($context->lookupFunction(self::ABI), $classId);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'is_anonymous_class_bridge_entry',
            [$i64],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED,
            '#19969'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

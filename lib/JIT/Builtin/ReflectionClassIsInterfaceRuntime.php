<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/** JIT/AOT link for ReflectionClass::isInterface() (#18335). */
final class ReflectionClassIsInterfaceRuntime
{
    private const ABI = '__phpc_refl_class_is_interface';

    private const HELPER_PATH = '/ext/standard/ReflectionClassKindJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\standard\\ReflectionClassKindJitHelper::probeIsInterface';

    /** @var list<string> */
    private const COMPILED = [self::HELPER];

    public static function invoke(Context $context, Value $nameStr): Value
    {
        return $context->builder->call($context->lookupFunction(self::ABI), $nameStr);
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

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'refl_class_is_interface_bridge_entry',
            [$strPtr],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED,
            '#18335'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

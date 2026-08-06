<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for settype() in-place casts via SettypeJitHelper PHP (#17335).
 *
 * Replaces inline LLVM in ext/standard/JitSettype.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmSettype}, {@see \PHPCompiler\ext\standard\SettypeJitHelper}
 * php-src: ext/standard/type.c — php_settype
 */
final class SettypeRuntime
{
    private const ABI = '__phpc_jit_settype_in_place';

    private const HELPER_PATH = '/ext/standard/SettypeJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\SettypeJitHelper::applyInPlace';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function applyInPlace(Context $context, Value $valuePtr, string $typeName): void
    {
        self::ensureLinked($context);
        // ABI is `%__string__*` (JitVmHelperLink bridge) — raw `constantFromString` is
        // `[N x i8]*` / cstr and fails module verify under user-script AOT (#27090 / peer #26884).
        // constantStringFromString may emit on a temp init builder / leave insert on __init__
        // under thin AOT — resume the caller's BB before load+call or the cast never runs in main.
        $resume = BasicBlockHelper::tryGetInsertBlock($context);
        $typeGlobal = $context->constantStringFromString($typeName);
        if (null !== $resume) {
            BasicBlockHelper::restoreInsertBlock($context, $resume);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_type_str_cont');
        }
        $typeStr = $context->builder->load($typeGlobal);
        $context->builder->call(
            $context->lookupFunction(self::ABI),
            $valuePtr,
            $typeStr
        );
    }

    private static function implement(Context $context): void
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

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        // NestedJIT of SettypeJitHelper / value-box promote can reference
        // `__compiler_is_resource` while JitStreamLifecycleKernel::implement no-ops under
        // NestedJitCompileScope — emit the thin body before the bridge (#27090).
        if ($context->isThinStandaloneAotMain()) {
            StreamGlobalsJit::implementThinIsResource($context);
        }
        StreamLifecycle::ensureLinked($context);
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'settype_in_place_bridge_entry',
            [$valuePtr, $strPtr],
            $void,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17335'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

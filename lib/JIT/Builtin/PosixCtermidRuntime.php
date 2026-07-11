<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_ctermid() via PosixCtermidJitHelper PHP (#12684).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc ctermid LLVM (#13041).
 * SSOT: {@see \PHPCompiler\ext\posix\VmPosixCtermidPure}
 */
final class PosixCtermidRuntime
{
    private const ABI_PATH = '__posix_ctermid__path';

    private const HELPER_PATH = '/ext/posix/PosixCtermidJitHelper.php';

    private const PATH_HELPER = 'PHPCompiler\\ext\\posix\\PosixCtermidJitHelper::path';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PATH_HELPER,
    ];

    public static function ctermid(Context $context): Value
    {
        self::ensureLinked($context);
        $msgStr = $context->builder->call(
            $context->lookupFunction(self::ABI_PATH)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $msgStr
        );

        return $ptr;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_PATH);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PATH,
            'posix_ctermid_path_bridge_entry',
            [],
            $strPtr,
            self::PATH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12684'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_PATH);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_PATH.' missing after PosixCtermidRuntime bridge (#12684)');
        }
        $context->registerFunction(self::ABI_PATH, $fn);
    }
}

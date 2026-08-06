<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc open/read kernel for FileGetContentsJitHelper (#26756).
 *
 * Avoids recursing through fopen()/fread() when the helper TU is compiled into
 * AOT modules (stream read currently returns empty / hangs). Peer {@see phpc_readfile_kernel}.
 */
final class phpc_file_get_contents_kernel extends Internal
{
    private const ABI = '__phpc_file_get_contents_libc';

    private const BRIDGE_ENTRY = 'fgc_libc_entry';

    public function __construct()
    {
        parent::__construct('phpc_file_get_contents_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_file_get_contents_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_file_get_contents_kernel', 0, 'filename', $frame);
        if (!VmOpenBasedir::check($path, true, 'file_get_contents', $frame->vmContext, $frame)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        $data = VmFs::fileGetContents($path);
        if (null !== $frame->returnVar) {
            if (false === $data) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->string((string) $data);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_file_get_contents_kernel() expects exactly 1 argument');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_file_get_contents_kernel', 0, 'filename');
        self::ensureLibcFunction($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function ensureLibcFunction(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        LibcExtern::register($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitFileGetContentsLibc::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

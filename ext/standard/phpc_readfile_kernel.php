<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc open/read/write(stdout) kernel for ReadfileJitHelper (#19966).
 *
 * Avoids recursing through readfile()/fopen() when the helper TU is compiled
 * into user-script AOT (same shape as NestedJIT FS leaves / {@see StringRename}).
 */
final class phpc_readfile_kernel extends Internal
{
    private const ABI = '__phpc_readfile_libc';

    public function __construct()
    {
        parent::__construct('phpc_readfile_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_readfile_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_readfile_kernel', 0, 'filename', $frame);
        $result = VmFs::readfile($path);
        if (null !== $frame->returnVar) {
            if (false === $result) {
                $frame->returnVar->int(-1);
            } else {
                $frame->returnVar->int((int) $result);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_readfile_kernel() expects exactly 1 argument');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_readfile_kernel', 0, 'filename');
        self::ensureLibcFunction($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function ensureLibcFunction(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        LibcExtern::register($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i64, false, $strPtr)
            );

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        JitReadfileLibc::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

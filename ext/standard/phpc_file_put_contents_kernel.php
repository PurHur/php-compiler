<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc fopen/fwrite kernel for FilePutContentsJitHelper (#19966).
 *
 * Avoids recursing through file_put_contents() when the helper TU is compiled
 * into user-script AOT (NestedJIT FS leaf peer {@see \PHPCompiler\JIT\Builtin\StringRename}).
 */
final class phpc_file_put_contents_kernel extends Internal
{
    private const ABI = '__phpc_file_put_contents_libc';

    public function __construct()
    {
        parent::__construct('phpc_file_put_contents_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('phpc_file_put_contents_kernel() expects 2-3 arguments, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_file_put_contents_kernel', 0, 'filename', $frame);
        $data = (string) $frame->calledArgs[1]->toString();
        $flags = 2 === $argc ? 0 : (int) $frame->calledArgs[2]->toInt();
        $written = VmFs::filePutContents($path, $data, $flags);
        if (null !== $frame->returnVar) {
            if (false === $written) {
                $frame->returnVar->int(-1);
            } else {
                $frame->returnVar->int((int) $written);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('phpc_file_put_contents_kernel() expects 2-3 arguments');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_file_put_contents_kernel', 0, 'filename');
        $data = JitStringBuiltinArg::lower($context, $args[1], 'phpc_file_put_contents_kernel', 1, 'data');
        $i64 = $context->getTypeFromString('int64');
        // NestedJIT helpers pass flags as __value__ boxes — trunc(loadValue) is invalid IR (#20266).
        $flags = 2 === $argc
            ? $i64->constInt(0, false)
            : JitLongArg::lower($context, $args[2], 'phpc_file_put_contents_kernel() flags');
        self::ensureLibcFunction($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path, $data, $flags);
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
                $context->context->functionType($i64, false, $strPtr, $strPtr, $i64)
            );

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        JitFilePutContentsLibc::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

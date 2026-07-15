<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc access(2) kernel for StatPathJitHelper (#19215).
 *
 * mode is POSIX R_OK/W_OK/X_OK (or F_OK).
 * php-src: ext/standard/filestat.c — php_access
 */
final class phpc_access_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_access_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_access_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_access_kernel', 0, 'filename', $frame);
        $mode = $frame->calledArgs[1]->toInt();
        $ok = false;
        if (!str_contains($path, "\0") && \function_exists('file_exists')) {
            // Mirror access(2): F_OK via file_exists; R/W/X via is_* when hosted under Zend.
            if (0 === $mode) {
                $ok = @\file_exists($path);
            } elseif (4 === $mode) {
                $ok = @\is_readable($path);
            } elseif (2 === $mode) {
                $ok = @\is_writable($path);
            } elseif (1 === $mode) {
                $ok = @\is_executable($path);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_access_kernel() expects exactly 2 arguments');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_access_kernel', 0, 'filename');
        $mode = 0;
        if (JITVariable::TYPE_NATIVE_LONG === $args[1]->type && JITVariable::KIND_VALUE === $args[1]->kind) {
            try {
                $mode = (int) $args[1]->value->getConstantValue();
            } catch (\Throwable) {
                $mode = 0;
            }
        }

        return JitStatKernel::accessOk($context, $path, $mode);
    }
}

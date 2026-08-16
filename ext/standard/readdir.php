<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * readdir() — VM via VmDir; JIT/AOT via __compiler_readdir (issue #3235, #31450).
 *
 * php-src: ext/standard/dir.c / dir.stub.php — function readdir($dir_handle = null): string|false
 */
final class readdir extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'readdir', 0, 1);
        $arg = $frame->calledArgs[0] ?? null;
        $handle = VmDirArg::resolveOptionalDirHandle($arg, 'readdir');
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmDir::readdir($handle);
        if (false === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($entry);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'readdir', 0, 1)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return $ptr;
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        // Sentinel -1 → DirHandleJitHelper uses EG(default_directory) (#31450).
        $handleLong = (0 === \count($args) || self::isCompileTimeNull($args[0]))
            ? $i64->constInt(-1, true)
            : $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'readdir() handle'),
                $i64
            );

        return JitReaddir::invoke($context, $handleLong);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}

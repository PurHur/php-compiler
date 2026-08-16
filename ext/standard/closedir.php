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
 * closedir() — VM via VmDir; JIT/AOT via __compiler_closedir (issue #3235, #27999).
 *
 * php-src: ext/standard/dir.c / dir.stub.php — function closedir($dir_handle = null): void
 */
final class closedir extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'closedir', 0, 1);
        $arg = $frame->calledArgs[0] ?? null;
        $handle = VmDirArg::resolveOptionalDirHandle($arg, 'closedir');
        VmDir::closedir($handle);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'closedir', 0, 1)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return $ptr;
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        // Sentinel -1 → DirHandleJitHelper uses EG(default_directory) (#27999).
        $handleLong = (0 === \count($args) || self::isCompileTimeNull($args[0]))
            ? $i64->constInt(-1, true)
            : $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'closedir() handle'),
                $i64
            );
        $context->builder->call(
            $context->lookupFunction('__compiler_closedir'),
            $handleLong
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}

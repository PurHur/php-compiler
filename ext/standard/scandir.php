<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * scandir() — list directory entries (VM via VmDir; JIT via StringFsGlobVecJit, #7405).
 *
 * Optional `$context` (arity 1..3) — #30569; php-src ext/standard/dir.c.
 */
final class scandir extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..3 — #30569.
        $this->requireArgCountRange($frame, 'scandir', 1, 3);
        $argc = \count($frame->calledArgs);
        $path = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'scandir', 'directory', false);
        VmString::rejectEmptyBuiltinStringArg($path, 'scandir', 0, 'directory', true);
        $sortingOrder = \SCANDIR_SORT_ASCENDING;
        if ($argc >= 2) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31244).
            $sortingOrder = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'scandir',
                2,
                'sorting_order'
            );
        }
        if ($argc >= 3) {
            VmStreamContext::validateOptionalContextArg(
                $frame->calledArgs[2],
                'scandir',
                3
            );
        }
        $result = VmDir::scandir($path, $sortingOrder);
        if (false === $result) {
            VmFilestatFailure::warnScandirFailed($frame, $path);
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->array(VmFs::stringListToArray($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30569).
        if (!$this->requireArgCountRangeJit($context, $args, 'scandir', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i32 = $context->getTypeFromString('int32');
        $sort = $i32->constInt(0, false);
        if ($argc >= 2) {
            // Early return after compile-time null TypeError (AOT verify; peer dirname #31210 / chmod #31211).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitSleep::zParamLong($context, $args[1], 'scandir', 2, 'sorting_order');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'scandir_null_sorting_order_te_cont');
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            $sortLong = JitSleep::zParamLong($context, $args[1], 'scandir', 2, 'sorting_order');
            $sort = $context->builder->truncOrBitCast($sortLong, $i32);
        }
        if ($argc >= 3) {
            JitStreamContextOptionalArg::validate($context, $args[2], 'scandir', 3);
        }

        $path = self::jitDirectoryArg($context, $args[0]);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $path,
            VmString::emptyStringArgValueErrorMessageCannot('scandir', 0, 'directory')
        );
        StringFsGlob::ensureLinked($context);

        return JitFsGlob::scandir($context, $path, $sort);
    }

    /**
     * Z_PARAM_STR $directory (php-src ext/standard/dir.c; #4582).
     *
     * @throws \TypeError when the operand is not a string like Zend PHP 8.x
     */
    private static function vmDirectoryArg(Variable $var): string
    {
        return VmString::coerceStringBuiltinArg($var, 'scandir', 0, 'directory', 'string', false);
    }

    private static function jitDirectoryArg(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lower($context, $arg, 'scandir', 0, 'directory', 'string', null, false);
    }
}

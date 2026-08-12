<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** scandir() — list directory entries (VM via VmDir; JIT via StringFsGlobVecJit, #7405). */
final class scandir extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('scandir() requires one or two arguments in this compiler build');
        }
        $path = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'scandir', 'directory', false);
        VmString::rejectEmptyBuiltinStringArg($path, 'scandir', 0, 'directory', true);
        $sortingOrder = \SCANDIR_SORT_ASCENDING;
        if (2 === $argc) {
            $sortingOrder = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'scandir',
                2,
                'sorting_order'
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('scandir() requires one or two arguments in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $sort = $i32->constInt(0, false);
        if (2 === $argc) {
            $sortLong = JitSleep::zParamLong($context, $args[1], 'scandir', 2, 'sorting_order');
            $sort = $context->builder->truncOrBitCast($sortLong, $i32);
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

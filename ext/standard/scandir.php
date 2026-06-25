<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
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
        $path = self::vmDirectoryArg($frame->calledArgs[0]);
        VmString::rejectEmptyBuiltinStringArg($path, 'scandir', 0, 'directory');
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
            'scandir(): Argument #1 ($directory) cannot be empty'
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
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                'scandir(): Argument #1 ($directory) must be of type string, %s given',
                EnumCaseSupport::isEnumCaseVariable($var)
                    ? EnumCaseSupport::typeNameForVariable($var)
                    : self::vmTypeName($var->type)
            ));
        }

        return $var->toString();
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    private static function jitDirectoryArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            return JitStringBuiltinArg::lower($context, $arg, 'scandir', 0, 'directory');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return JitStringBuiltinArg::lower($context, $arg, 'scandir', 0, 'directory');
        }
        if (JITVariable::TYPE_STRING !== $arg->type) {
            self::emitJitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'scandir(): Argument #1 ($directory) must be of type string, %s given',
                    JitOperandTypeLabel::givenLabel($context, $arg)
                )
            );

            return $context->getTypeFromString('__string__*')->constNull();
        }

        return $context->helper->loadValue($arg);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}

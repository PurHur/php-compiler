<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SettypeRuntime;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * settype() JIT lowering — delegates in-place cast to SettypeJitHelper PHP (#3151, #17335).
 *
 * php-src: ext/standard/type.c — php_settype
 * SSOT: {@see VmSettype}, {@see SettypeJitHelper}
 */
final class JitSettype
{
    public static function invoke(Context $context, JITVariable $var, JITVariable $typeArg): Value
    {
        $typeLit = JitStringArg::compileTimeLiteral($typeArg);
        if (null === $typeLit) {
            throw new \LogicException(
                'settype() with a non-constant type name is not supported for JIT in this compiler build'
            );
        }

        $type = strtolower($typeLit);
        if ('resource' === $type) {
            throw new \ValueError('Cannot convert to resource type');
        }

        $canonical = self::canonicalTypeName($type);
        if (null === $canonical) {
            throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        // Typed locals (e.g. `__string__**` under thin AOT) must share one `__value__` lvalue —
        // valuePtrFromVariable otherwise boxes into a temp and the cast never reaches `$var` (#27090).
        if ($context->isThinStandaloneAotMain()) {
            // Promote / value writers may ref `__compiler_is_resource` (#27090).
            $resumeIsRes = BasicBlockHelper::tryGetInsertBlock($context);
            StreamGlobalsJit::implementThinIsResource($context);
            if (null !== $resumeIsRes) {
                BasicBlockHelper::restoreInsertBlock($context, $resumeIsRes);
            } else {
                BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_after_is_resource');
            }
        }
        JitValueBox::promoteNativeLvalueToValueBox($context, $var);
        $destPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $var)
        );

        // Compile-time subject + type: fold via VmSettype into the shared value box. NestedJIT
        // SettypeJitHelper does not observe thin-AOT named-local promotions (#27090).
        if (self::tryFoldCompileTime($context, $var, $destPtr, $canonical)) {
            return $context->constantFromBool(true);
        }

        SettypeRuntime::applyInPlace($context, $destPtr, $canonical);

        return $context->constantFromBool(true);
    }

    /**
     * When the subject has a compile-time scalar, apply {@see VmSettype} and store into $destPtr.
     */
    private static function tryFoldCompileTime(
        Context $context,
        JITVariable $var,
        Value $destPtr,
        string $canonical
    ): bool {
        $vm = self::compileTimeVmSubject($var);
        if (null === $vm) {
            return false;
        }
        // array/object results need runtime helper (allocations / stdClass).
        if (\in_array($canonical, ['array', 'object'], true)) {
            return false;
        }
        VmSettype::apply($vm, $canonical, null);
        if (!self::storeVmResult($context, $destPtr, $vm)) {
            return false;
        }
        $var->compileTimeString = null;
        $var->compileTimeLong = null;
        $resolved = $vm->resolveIndirect();
        if (VmVariable::TYPE_INTEGER === $resolved->type) {
            $var->compileTimeLong = $resolved->toInt();
        } elseif (VmVariable::TYPE_STRING === $resolved->type) {
            $var->compileTimeString = $resolved->toString();
        }

        return true;
    }

    private static function compileTimeVmSubject(JITVariable $var): ?VmVariable
    {
        if (null !== $var->compileTimeString) {
            $vm = new VmVariable();
            $vm->string($var->compileTimeString);

            return $vm;
        }
        if (null !== $var->compileTimeLong) {
            $vm = new VmVariable();
            $vm->int($var->compileTimeLong);

            return $vm;
        }
        if ($var->isNullConstant ?? false) {
            $vm = new VmVariable();
            $vm->null();

            return $vm;
        }

        return null;
    }

    private static function storeVmResult(Context $context, Value $destPtr, VmVariable $vm): bool
    {
        $resolved = $vm->resolveIndirect();
        switch ($resolved->type) {
            case VmVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);

                return true;
            case VmVariable::TYPE_INTEGER:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $context->constantFromInteger($resolved->toInt())
                );

                return true;
            case VmVariable::TYPE_FLOAT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    $context->constantFromFloat($resolved->toFloat())
                );

                return true;
            case VmVariable::TYPE_BOOLEAN:
                JitValueBox::writeBool(
                    $context,
                    $destPtr,
                    $context->constantFromBool($resolved->toBool())
                );

                return true;
            case VmVariable::TYPE_STRING:
                $resume = BasicBlockHelper::tryGetInsertBlock($context);
                $global = $context->constantStringFromString($resolved->toString());
                if (null !== $resume) {
                    BasicBlockHelper::restoreInsertBlock($context, $resume);
                } else {
                    BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_fold_str_cont');
                }
                $str = $context->builder->load($global);
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $str
                );

                return true;
            default:
                return false;
        }
    }

    private static function canonicalTypeName(string $type): ?string
    {
        switch ($type) {
            case 'integer':
            case 'int':
                return 'integer';
            case 'double':
            case 'float':
                return 'double';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'string':
            case 'array':
            case 'null':
            case 'object':
                return $type;
            default:
                return null;
        }
    }
}

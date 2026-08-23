<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;

/**
 * Z_PARAM_OBJECT_OF_CLASS_OR_NULL(DOMNode) for saveXML/saveHTML arg #1 (#31396).
 *
 * php-src: ext/dom/php_dom.stub.php — ?DOMNode $node; lone int/string is never $options.
 * Named {@code saveXML(options: …)} resolves to arg #2 with omitted $node at #1 (#25182).
 */
final class JitDomSaveOptionalDomNodeArg
{
    private static int $seq = 0;

    /**
     * @return bool true when compile-time invalid type was handled (caller must return immediately)
     */
    public static function guardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex = 1,
        string $paramName = 'node'
    ): bool {
        if (NamedOptionalCallArgs::isOmittedOptional($arg)) {
            return false;
        }

        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return false;
        }

        if (JITVariable::TYPE_OBJECT === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            if (JITVariable::TYPE_VALUE === $arg->type) {
                self::emitRuntimeTypeErrorUnlessNullOrObject(
                    $context,
                    $arg,
                    $function,
                    $userArgIndex,
                    $paramName
                );
            }

            return false;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_save_opt_node_ty');
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::message($function, $userArgIndex, $paramName, self::typeLabel($arg))
        );

        return true;
    }

    private static function emitRuntimeTypeErrorUnlessNullOrObject(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_save_opt_node_rt');
        $tag = (string) (self::$seq++);
        $nullBlock = BasicBlockHelper::append($context, 'dom_save_opt_node_rt_null_'.$tag);
        $objBlock = BasicBlockHelper::append($context, 'dom_save_opt_node_rt_obj_'.$tag);
        $badBlock = BasicBlockHelper::append($context, 'dom_save_opt_node_rt_bad_'.$tag);

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT, false)
        );
        $isNullOrObj = $context->builder->or($isNull, $isObject);
        $context->builder->branchIf($isNullOrObj, $nullBlock, $badBlock);

        $context->builder->positionAtEnd($badBlock);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::message($function, $userArgIndex, $paramName, 'mixed')
        );

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($objBlock);
        $context->builder->positionAtEnd($objBlock);
    }

    private static function message(
        string $function,
        int $userArgIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type ?DOMNode, %s given',
            $function,
            $userArgIndex,
            $paramName,
            $given
        );
    }

    private static function typeLabel(JITVariable $value): string
    {
        return match ($value->type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_NULL => 'null',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => 'mixed',
        };
    }
}

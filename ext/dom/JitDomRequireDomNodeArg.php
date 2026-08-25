<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Z_PARAM_OBJ_OF_CLASS(DOMNode) guard — Zend TypeError (#30410 / #32558 / #33716).
 *
 * php-src: ext/dom/php_dom.stub.php — DOMNode::appendChild(DOMNode $node), etc.
 * Literal null is TYPE_VALUE + isNullConstant; variable null is TYPE_VALUE without
 * that flag — {@see __value__readObject} on a null box SIGSEGVs under AOT (#33716).
 * Non-null scalars (int/bool/float/string/array) must TypeError at runtime like Zend
 * instead of LogicException at compile time or SIGSEGV on readObject (#34716).
 * ParentNode/ChildNode DOMNode|string rejects the same scalars (#34729; null was #33741).
 * insertBefore refChild is ?DOMNode — null OK, scalars TypeError (#34729).
 * Runtime branch mirrors {@see JitDomSaveOptionalDomNodeArg} (#34225) and
 * {@see JitDomInsertBefore} refChild (#33031).
 */
final class JitDomRequireDomNodeArg
{
    private static int $seq = 0;

    /**
     * @return bool true when compile-time invalid type was handled (caller must return immediately)
     */
    public static function guardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass = 'DOMNode'
    ): bool {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        if (self::isCompileTimeNull($arg)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_null');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, $expectedClass, 'null')
            );

            return true;
        }

        $scalar = self::compileTimeScalarLabel($arg);
        if (null !== $scalar) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_scalar');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, $expectedClass, $scalar)
            );

            return true;
        }

        // Variable null / boxed scalars: TYPE_VALUE, no isNullConstant / native type.
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeNonObjectTypeErrorOrContinue(
                $context,
                $arg,
                $function,
                $userArgIndex,
                $paramName,
                $expectedClass
            );
        }

        return false;
    }

    /**
     * ParentNode::append/prepend/replaceChildren + ChildNode — DOMNode|string
     * (php-src parentnode.c / childnode.c; #33741 / #33746 / #34729).
     *
     * Strings and objects are valid. Null and other scalars TypeError. Message
     * matches Zend (no $param name).
     *
     * @return bool true when compile-time invalid type was handled (caller must return immediately)
     */
    public static function guardDomNodeOrStringOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex
    ): bool {
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        if (self::isCompileTimeNull($arg)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_or_str_null');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::unionMessage($function, $userArgIndex, 'DOMNode|string', 'null')
            );

            return true;
        }

        // TYPE_STRING already allowed above — remaining compile-time scalars are invalid.
        $scalar = self::compileTimeScalarLabel($arg);
        if (null !== $scalar) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_or_str_scalar');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::unionMessage($function, $userArgIndex, 'DOMNode|string', $scalar)
            );

            return true;
        }

        // Variable null / boxed int|bool|float|array: TYPE_VALUE (string/object continue).
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeDomNodeOrStringTypeErrorOrContinue(
                $context,
                $arg,
                $function,
                $userArgIndex
            );
        }

        return false;
    }

    /**
     * insertBefore refChild — ?DOMNode (php-src node.c; #33031 / #34729).
     *
     * Null / omitted ≡ append. Non-null scalars must TypeError before readObject.
     *
     * @return bool true when compile-time invalid type was handled (caller must return immediately)
     */
    public static function guardOptionalDomNodeOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): bool {
        if (JITVariable::TYPE_OBJECT === $arg->type || self::isCompileTimeNull($arg)) {
            return false;
        }

        $scalar = self::compileTimeScalarLabel($arg);
        if (null !== $scalar) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_opt_node_scalar');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, '?DOMNode', $scalar)
            );

            return true;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeOptionalDomNodeTypeErrorOrContinue(
                $context,
                $arg,
                $function,
                $userArgIndex,
                $paramName
            );
        }

        return false;
    }

    public static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    private static function compileTimeScalarLabel(JITVariable $arg): ?string
    {
        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function message(
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $userArgIndex,
            $paramName,
            $expectedClass,
            $given
        );
    }

    /** Zend union-type message — no $param name (ParentNode / ChildNode stubs). */
    private static function unionMessage(
        string $function,
        int $userArgIndex,
        string $expected,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d must be of type %s, %s given',
            $function,
            $userArgIndex,
            $expected,
            $given
        );
    }

    /**
     * Reject runtime null / int / string / bool / float / array before readObject.
     *
     * Leaves the builder positioned in the non-scalar continuation block.
     */
    private static function emitRuntimeNonObjectTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass
    ): void {
        self::emitRuntimeKindTypeErrors(
            $context,
            $arg,
            'dom_req_node_rt',
            [
                [JITVariable::TYPE_NULL, 'null'],
                [JITVariable::TYPE_NATIVE_LONG, 'int'],
                [JITVariable::TYPE_NATIVE_DOUBLE, 'float'],
                [JITVariable::TYPE_NATIVE_BOOL, 'bool'],
                [JITVariable::TYPE_STRING, 'string'],
                [JITVariable::TYPE_HASHTABLE, 'array'],
            ],
            static fn (string $label): string => self::message(
                $function,
                $userArgIndex,
                $paramName,
                $expectedClass,
                $label
            )
        );
    }

    /**
     * DOMNode|string — reject null/int/bool/float/array; allow string + object (#34729).
     */
    private static function emitRuntimeDomNodeOrStringTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex
    ): void {
        self::emitRuntimeKindTypeErrors(
            $context,
            $arg,
            'dom_req_node_or_str_rt',
            [
                [JITVariable::TYPE_NULL, 'null'],
                [JITVariable::TYPE_NATIVE_LONG, 'int'],
                [JITVariable::TYPE_NATIVE_DOUBLE, 'float'],
                [JITVariable::TYPE_NATIVE_BOOL, 'bool'],
                [JITVariable::TYPE_HASHTABLE, 'array'],
            ],
            static fn (string $label): string => self::unionMessage(
                $function,
                $userArgIndex,
                'DOMNode|string',
                $label
            )
        );
    }

    /**
     * ?DOMNode — reject int/bool/float/string/array; allow null + object (#34729).
     */
    private static function emitRuntimeOptionalDomNodeTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): void {
        self::emitRuntimeKindTypeErrors(
            $context,
            $arg,
            'dom_req_opt_node_rt',
            [
                [JITVariable::TYPE_NATIVE_LONG, 'int'],
                [JITVariable::TYPE_NATIVE_DOUBLE, 'float'],
                [JITVariable::TYPE_NATIVE_BOOL, 'bool'],
                [JITVariable::TYPE_STRING, 'string'],
                [JITVariable::TYPE_HASHTABLE, 'array'],
            ],
            static fn (string $label): string => self::message(
                $function,
                $userArgIndex,
                $paramName,
                '?DOMNode',
                $label
            )
        );
    }

    /**
     * @param list<array{0: int, 1: string}> $checks
     * @param callable(string): string       $messageForLabel
     */
    private static function emitRuntimeKindTypeErrors(
        Context $context,
        JITVariable $arg,
        string $blockPrefix,
        array $checks,
        callable $messageForLabel
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, $blockPrefix);
        $tag = (string) (self::$seq++);
        $okBlock = BasicBlockHelper::append($context, $blockPrefix.'_ok_'.$tag);

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        foreach ($checks as [$typeConst, $label]) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt($typeConst & 0x7f, false)
            );
            $labelBlock = BasicBlockHelper::append($context, $blockPrefix.'_'.$label.'_'.$tag);
            $nextProbe = BasicBlockHelper::append($context, $blockPrefix.'_next_'.$label.'_'.$tag);
            $context->builder->branchIf($match, $labelBlock, $nextProbe);
            $context->builder->positionAtEnd($labelBlock);
            ExceptionBridge::emitTypeErrorAndAbort($context, $messageForLabel($label));
            $context->builder->positionAtEnd($nextProbe);
        }

        $context->builder->branch($okBlock);
        $context->builder->positionAtEnd($okBlock);
    }
}

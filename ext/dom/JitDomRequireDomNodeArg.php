<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Z_PARAM_OBJ_OF_CLASS(DOMNode) compile-time guard — Zend TypeError (#30410 / #32558).
 *
 * php-src: ext/dom/php_dom.stub.php — DOMNode::appendChild(DOMNode $node), etc.
 * Literal null is TYPE_VALUE + isNullConstant; readObject on that box SIGSEGVs under AOT.
 */
final class JitDomRequireDomNodeArg
{
    /**
     * @return bool true when compile-time null was handled (caller must return immediately)
     */
    public static function guardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): bool {
        if (!self::isCompileTimeNull($arg)) {
            return false;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_null');
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type DOMNode, null given',
                $function,
                $userArgIndex,
                $paramName
            )
        );

        return true;
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
}

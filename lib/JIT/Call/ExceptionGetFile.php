<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getFile() — read file property (#30895).
 *
 * php-src: Zend/zend_exceptions.c — zim_Exception_getFile / zim_Error_getFile
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetFile}
 */
final class ExceptionGetFile implements Call
{
    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getFile() requires an object receiver');
        }
        // php-src: ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getFile() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_getfile_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $context->builder->load($context->constantStringFromString(''))
            );

            return $slot;
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $fileVar = $context->type->object->propertyFetch(
            $obj,
            $this->declaringRoot,
            ExceptionSupport::PROP_FILE
        );

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $fileVar)
        );
    }
}

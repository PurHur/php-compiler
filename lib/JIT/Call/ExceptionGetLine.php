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
 * Throwable/Exception::getLine() — read line property (#30895).
 *
 * php-src: Zend/zend_exceptions.c — zim_Exception_getLine / zim_Error_getLine
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetLine}
 */
final class ExceptionGetLine implements Call
{
    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getLine() requires an object receiver');
        }
        // php-src: ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getLine() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_getline_argc_cont');
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $slot, $context->constantFromInteger(0, 'int64'));

            return $slot;
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $line = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            $this->declaringRoot,
            ExceptionSupport::PROP_LINE
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $line);

        return $slot;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeMethodReturn;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getCode() — read code property (#23974, peer #23641 getMessage, #30895).
 *
 * php-src: Zend/zend_exceptions.stub.php — Exception::getCode(): int
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetCode}
 */
final class ExceptionGetCode implements Call
{
    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getCode() requires an object receiver');
        }
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getCode() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_getcode_argc_cont');

            return JitNativeMethodReturn::longZero($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $code = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            $this->declaringRoot,
            ExceptionSupport::PROP_CODE
        );
        return JitNativeMethodReturn::long($context, $code);
    }
}

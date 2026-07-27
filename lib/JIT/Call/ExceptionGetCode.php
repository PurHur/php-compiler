<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getCode() — read code property (#23974, peer #23641 getMessage).
 *
 * php-src: Zend/zend_exceptions.stub.php — Exception::getCode(): int
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetCode}
 */
final class ExceptionGetCode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getCode() requires an object receiver');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $code = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'Exception',
            ExceptionSupport::PROP_CODE
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $code);

        return $slot;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitUnserialize
{
    public static function decodeRuntime(Context $context, JITVariable $payload): Value
    {
        $payloadString = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $payload,
            'unserialize',
            0,
            'data'
        );
        StringUnserialize::ensureLinked($context);

        return self::decodeRuntimeString($context, $payloadString);
    }

    /**
     * @param array<string, mixed> $options compile-time options (allowed_classes, max_depth)
     */
    public static function decodeRuntimeWithOptions(
        Context $context,
        JITVariable $payload,
        array $options
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($payload);
        if (null !== $literal) {
            $decoded = VmUnserializeFormat::decodePayload($literal, $options);
            if (false === $decoded) {
                return JitJsonDecode::materializeScalar($context, false);
            }
            if (null === $decoded) {
                return JitJsonDecode::materializeNull($context);
            }
            if (\is_bool($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_int($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_string($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_array($decoded)) {
                return JitJsonDecode::materializeArray($context, $decoded);
            }

            throw new \LogicException('unserialize() result type not supported in this compiler build');
        }

        if ([] === $options || (1 === \count($options) && \array_key_exists('allowed_classes', $options) && true === $options['allowed_classes'])) {
            return self::decodeRuntime($context, $payload);
        }

        throw new \LogicException('unserialize() runtime payload with options not supported for JIT in this compiler build');
    }

    public static function decodeRuntimeString(Context $context, Value $payloadString): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unserialize'),
            $payloadString,
            $ptr
        );

        return $ptr;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * unserialize() — scalar/array via VmUnserializeFormat; objects via VmSerialize (JIT/AOT: __compiler_unserialize).
 */
final class unserialize extends Internal
{
    public function __construct()
    {
        parent::__construct('unserialize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('unserialize() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $payloadVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $payloadVar->type) {
            throw new \LogicException('unserialize() first argument must be a string in this compiler build');
        }
        $options = null;
        if ($argc > 1) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optionsVar->type) {
                throw new \LogicException('unserialize() options must be an array in this compiler build');
            }
            $options = self::extractUnserializeOptions($optionsVar);
        }
        $payload = $payloadVar->toString();
        $decoded = VmSerialize::unserializePayload(
            $frame->vmContext,
            $payload,
            $options,
            $frame
        );
        if (false === $decoded) {
            self::emitParseFailureNotice($frame, $payload, $options);
            $frame->returnVar->bool(false);

            return;
        }
        if ($decoded instanceof Variable) {
            $frame->returnVar->copyFrom($decoded);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('unserialize() requires at least one argument');
        }
        $options = null;
        if (\count($args) > 1) {
            $options = JitUnserializeOptions::tryCompileTime(
                $context,
                $args[1],
                $context->jitEnclosingBlock,
                $context->jitUnserializeOptionsOperand
            );
            if (null === $options) {
                throw new \LogicException('unserialize() runtime options not supported in this compiler build');
            }
        }

        $compileTime = self::compileTimeUnserialize($context, $args[0], $options);
        if (null !== $compileTime) {
            return $compileTime;
        }

        if (null !== $options) {
            return JitUnserialize::decodeRuntimeWithOptions($context, $args[0], $options);
        }

        return JitUnserialize::decodeRuntime($context, $args[0]);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function compileTimeUnserialize(Context $context, JITVariable $arg, ?array $options = null): ?Value
    {
        if (JITVariable::TYPE_STRING !== $arg->type) {
            return null;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null === $literal) {
            return null;
        }
        $decoded = VmUnserializeFormat::decodePayload($literal, $options);
        if (false === $decoded) {
            return $context->helper->loadValue(
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt(0, false)
                )
            );
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

    /**
     * @return array<string, mixed>
     */
    public static function parseUnserializeOptionsArray(Variable $optionsVar): array
    {
        $options = [];
        foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $value]) {
            $keyVar = $keyVar->resolveIndirect();
            $key = Variable::TYPE_STRING === $keyVar->type
                ? $keyVar->toString()
                : (string) $keyVar->toInt();
            $resolved = $value->resolveIndirect();
            if ('allowed_classes' === $key) {
                if (Variable::TYPE_BOOLEAN === $resolved->type) {
                    $options['allowed_classes'] = $resolved->toBool();
                } elseif (Variable::TYPE_ARRAY === $resolved->type) {
                    $allowed = [];
                    foreach ($resolved->toArray()->iterate(true) as $entry) {
                        $entry = $entry->resolveIndirect();
                        if (Variable::TYPE_STRING === $entry->type) {
                            $allowed[] = $entry->toString();
                        }
                    }
                    $options['allowed_classes'] = $allowed;
                } else {
                    throw new \LogicException('allowed_classes must be of type bool or array');
                }
                continue;
            }
            if ('max_depth' === $key) {
                if (Variable::TYPE_INTEGER !== $resolved->type) {
                    throw new \LogicException('max_depth must be of type int');
                }
                $options['max_depth'] = $resolved->toInt();
                continue;
            }
            throw new \LogicException(
                'unserialize() option '.$key.' not supported in this compiler build'
            );
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractUnserializeOptions(Variable $optionsVar): array
    {
        return self::parseUnserializeOptionsArray($optionsVar);
    }

    /** php-src var_unserializer.c — E_WARNING on max_depth, then E_NOTICE + error_get_last (#13715, #9206). */
    private static function emitParseFailureNotice(Frame $frame, string $payload, ?array $options = null): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $depthLimit = VmUnserializeFormat::lastMaxDepthExceeded();
        if (null !== $depthLimit) {
            $frame->vmContext->errors->triggerError(
                \sprintf(
                    'unserialize(): Maximum depth of %d exceeded. The depth limit can be changed using the max_depth unserialize() option or the unserialize_max_depth ini setting',
                    $depthLimit
                ),
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        $offset = VmUnserializeFormat::lastErrorOffset();
        $length = VmUnserializeFormat::lastPayloadLength();
        if (null === $offset || null === $length) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf('unserialize(): Error at offset %d of %d bytes', $offset, $length),
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

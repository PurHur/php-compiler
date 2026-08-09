<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
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
        // php-src ext/standard/var.c — ArgumentCountError (#28474).
        $this->requireArgCountRange($frame, 'unserialize', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        // Soft-null DEP+coerce on 8.4 — Zend Z_PARAM_STR (#21223; reverts #18840 TypeError).
        $payload = VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'unserialize',
            0,
            'data'
        );
        $options = null;
        if ($argc > 1) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optionsVar->type) {
                // php-src ext/standard/var.c — Z_PARAM_ARRAY for options (#24149).
                throw new \TypeError(
                    'unserialize(): Argument #2 ($options) must be of type array, '
                    .EnumCaseSupport::typeNameForVariable($optionsVar).' given'
                );
            }
            $options = self::extractUnserializeOptions($optionsVar);
        }
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
        if (!$this->requireArgCountRangeJit($context, $args, 'unserialize', 1, 2)) {
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
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
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                $message = 'unserialize(): Argument #1 ($data) must be of type string, null given';
                if (null !== TryCatchHelper::resolveThrowHandler($context)) {
                    TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

                    return JitJsonDecode::materializeScalar($context, false);
                }

                return null;
            }
            // Soft-null: empty payload → false (same as unserialize('')) (#21223).
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'unserialize', 0, 'data');

            return $context->helper->loadValue(
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt(0, false)
                )
            );
        }
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
                    // php-src ext/standard/var.c — php_var_unserialize_with_options (#24149).
                    throw new \TypeError(self::allowedClassesOptionTypeErrorMessage($resolved));
                }
                continue;
            }
            if ('max_depth' === $key) {
                if (Variable::TYPE_INTEGER !== $resolved->type) {
                    // php-src ext/standard/var.c — Option "max_depth" must be int (#24149).
                    throw new \TypeError(
                        'unserialize(): Option "max_depth" must be of type int, '
                        .EnumCaseSupport::typeNameForVariable($resolved).' given'
                    );
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

    /**
     * Zend message for wrong-type allowed_classes (php-src ext/standard/var.c; #24149).
     */
    public static function allowedClassesOptionTypeErrorMessage(Variable $value): string
    {
        return 'unserialize(): Option "allowed_classes" must be of type array|bool, '
            .EnumCaseSupport::typeNameForVariable($value).' given';
    }

    /**
     * Zend message for wrong-type allowed_classes from a native PHP value (#24149).
     */
    public static function allowedClassesOptionTypeErrorMessageFromMixed(mixed $value): string
    {
        return 'unserialize(): Option "allowed_classes" must be of type array|bool, '
            .self::zendMixedTypeName($value).' given';
    }

    private static function zendMixedTypeName(mixed $value): string
    {
        if (\is_object($value)) {
            return $value::class;
        }
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return 'bool';
        }
        if (\is_int($value)) {
            return 'int';
        }
        if (\is_float($value)) {
            return 'float';
        }
        if (\is_string($value)) {
            return 'string';
        }
        if (\is_array($value)) {
            return 'array';
        }
        if (\is_resource($value)) {
            return 'resource';
        }

        return 'mixed';
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

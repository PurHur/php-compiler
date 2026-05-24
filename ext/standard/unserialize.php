<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * unserialize() — assoc arrays with scalar values (VM delegates to PHP; JIT/AOT via __compiler_unserialize).
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
        if ($argc > 1) {
            throw new \LogicException('unserialize() options not supported in this compiler build');
        }
        $decoded = VmSerialize::unserializePayload($frame->vmContext, $payloadVar->toString());
        if (false === $decoded) {
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
        if (\count($args) > 1) {
            throw new \LogicException('unserialize() options not supported in this compiler build');
        }

        $compileTime = self::compileTimeUnserialize($context, $args[0]);
        if (null !== $compileTime) {
            return $compileTime;
        }

        return JitUnserialize::decodeRuntime($context, $args[0]);
    }

    private static function compileTimeUnserialize(Context $context, JITVariable $arg): ?Value
    {
        if (JITVariable::TYPE_STRING !== $arg->type) {
            return null;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null === $literal) {
            return null;
        }
        $decoded = @\unserialize($literal);
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
}

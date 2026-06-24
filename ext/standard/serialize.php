<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * serialize() — VmSerialize SSOT; JIT/AOT via SerializeJitHelper (#9180).
 */
final class serialize extends Internal
{
    public function __construct()
    {
        parent::__construct('serialize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('serialize() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $encoded = VmSerialize::serializeValue(
            $frame->vmContext,
            $frame->calledArgs[0],
            $frame
        );
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('serialize() requires at least one argument');
        }

        $compileTime = self::compileTimeSerialize($context, $args[0]);
        if (null !== $compileTime) {
            return $context->builder->load($context->constantStringFromString($compileTime));
        }

        return JitSerialize::encode($context, $args[0]);
    }

    private static function compileTimeSerialize(Context $context, JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return 'N;';
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value)
                ? 'b:0;'
                : 'b:1;';
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $n = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($arg->value->value);

            return 'i:'.$n.';';
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            $literal = JitStringArg::compileTimeLiteral($arg);
            if (null !== $literal) {
                return VmSerializeFormat::encodeStringLiteral($literal);
            }
        }

        return null;
    }
}

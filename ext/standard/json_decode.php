<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_decode() — assoc arrays with scalar values (VM delegates to PHP; JIT/AOT via __compiler_json_decode).
 */
final class json_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('json_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('json_decode() requires at least one argument');
        }
        $jsonVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $jsonVar->type) {
            throw new \LogicException('json_decode() first argument must be a string in this compiler build');
        }
        $assoc = false;
        if ($argc > 1) {
            $assocVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $assocVar->type) {
                throw new \LogicException('json_decode() assoc flag must be a boolean in this compiler build');
            }
            $assoc = $assocVar->toBool();
        }
        if ($argc > 2) {
            throw new \LogicException('json_decode() depth/flags not supported in this compiler build');
        }
        if (!$assoc) {
            throw new \LogicException('json_decode() requires assoc=true in this compiler build');
        }
        $decoded = \json_decode($jsonVar->toString(), true);
        VmJson::syncLastErrorFromHost();
        if (null === $frame->returnVar) {
            return;
        }
        if (!\is_array($decoded) && null !== $decoded) {
            $frame->returnVar->copyFrom(VmJson::import($decoded));

            return;
        }
        if (null === $decoded) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('json_decode() requires at least one argument');
        }
        if (\count($args) > 2) {
            throw new \LogicException('json_decode() depth/flags not supported in this compiler build');
        }
        if (1 === \count($args)) {
            throw new \LogicException('json_decode() requires assoc=true in this compiler build');
        }
        if (2 === \count($args)
            && JITVariable::TYPE_NATIVE_BOOL === $args[1]->type
            && JITVariable::KIND_VALUE === $args[1]->kind
            && 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($args[1]->value->value)) {
            throw new \LogicException('json_decode() requires assoc=true in this compiler build');
        }

        return JitJsonDecode::decodeRuntime($context, $args[0]);
    }
}

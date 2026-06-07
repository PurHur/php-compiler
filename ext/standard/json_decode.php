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
 * json_decode() — assoc arrays or stdClass object graphs (VM: host json_decode; JIT/AOT: __compiler_json_decode).
 *
 * php-src ref: ext/json/php_json.c — object vs array decode (#7188).
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
        $decoded = \json_decode($jsonVar->toString(), $assoc);
        VmJson::syncLastErrorFromHost();
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('json_decode() requires VM context in this compiler build');
        }
        $frame->returnVar->copyFrom(VmJson::importDecoded($decoded, $assoc, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('json_decode() requires at least one argument');
        }
        if (\count($args) > 2) {
            throw new \LogicException('json_decode() depth/flags not supported in this compiler build');
        }

        $assoc = self::resolveAssocFlag($context, $args);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $decoded = \json_decode($literal, $assoc);
            VmJson::syncLastErrorFromHost();

            return JitJsonDecode::materializeDecoded($context, $decoded, $assoc);
        }

        if ($assoc) {
            return JitJsonDecode::decodeRuntime($context, $args[0]);
        }

        return JitJsonDecode::decodeRuntimeObjectMode($context, $args[0]);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveAssocFlag(Context $context, array $args): bool
    {
        if (\count($args) < 2) {
            return false;
        }
        $assocArg = $args[1];
        if (JITVariable::TYPE_NATIVE_BOOL === $assocArg->type && JITVariable::KIND_VALUE === $assocArg->kind) {
            return 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($assocArg->value->value);
        }
        if (JITVariable::TYPE_BOOLEAN === $assocArg->type && JITVariable::KIND_VALUE === $assocArg->kind) {
            return 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($assocArg->value->value);
        }

        throw new \LogicException('json_decode() assoc flag must be a compile-time boolean in this compiler build');
    }
}

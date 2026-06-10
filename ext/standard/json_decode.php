<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_decode() — assoc arrays or stdClass object graphs (VM: VmJsonFormat; JIT/AOT: __compiler_json_decode).
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
        $json = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'json_decode',
            0,
            'json'
        );
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
        $decoded = VmJsonFormat::decode($json, $assoc);
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
            $decoded = VmJsonFormat::decode($literal, $assoc);

            return JitJsonDecode::materializeDecoded($context, $decoded, $assoc);
        }

        if ($assoc) {
            return JitJsonDecode::decodeRuntime($context, $args[0]);
        }

        // assoc=false runtime path is unsupported; still reject enum operands first (#5907).
        JitStringBuiltinArg::lower($context, $args[0], 'json_decode', 0, 'json');

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
        if (JITVariable::TYPE_NATIVE_LONG === $assocArg->type && JITVariable::KIND_VALUE === $assocArg->kind) {
            return 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($assocArg->value->value);
        }

        throw new \LogicException('json_decode() assoc flag must be a compile-time boolean in this compiler build');
    }
}

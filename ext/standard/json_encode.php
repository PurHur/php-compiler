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
 * json_encode() — assoc arrays with scalar values (VM delegates to PHP; JIT/AOT via __compiler_json_encode_hashtable).
 */
final class json_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('json_encode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('json_encode() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc > 1) {
            throw new \LogicException('json_encode() flags not supported in this compiler build');
        }
        $ctx = $frame->vmContext;
        $vm = null !== $ctx ? $ctx->runtime->vm : null;
        try {
            $value = VmJson::export($frame->calledArgs[0]->resolveIndirect(), $ctx, $vm);
        } catch (VmJsonExportException $e) {
            VmJson::setLastError($e->errorCode);
            $frame->returnVar->bool(false);

            return;
        }
        $encoded = \json_encode($value);
        VmJson::syncLastErrorFromHost();
        if (false === $encoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('json_encode() requires at least one argument');
        }
        if (\count($args) > 1) {
            throw new \LogicException('json_encode() flags not supported in this compiler build');
        }

        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $encoded = \json_encode($literal);
            if (false === $encoded) {
                throw new \LogicException('json_encode() failed');
            }

            return $context->builder->load($context->constantStringFromString($encoded));
        }

        return JitJsonEncode::encode($context, $args[0]);
    }
}

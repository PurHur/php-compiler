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
        $value = VmJson::export($frame->calledArgs[0]->resolveIndirect());
        $encoded = \json_encode($value);
        if (false === $encoded) {
            throw new \LogicException('json_encode() failed');
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

        return JitJsonEncode::encode($context, $args[0]);
    }
}

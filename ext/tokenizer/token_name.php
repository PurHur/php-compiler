<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** token_name() — map token id to name (ext/tokenizer/tokenizer_data.c; issue #6940). */
final class token_name extends Internal
{
    public function __construct()
    {
        parent::__construct('token_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('token_name() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $type = $frame->calledArgs[0]->toInt();

        $name = TokenConstants::nameForId($type);
        $frame->returnVar->string(null !== $name ? $name : 'UNKNOWN');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'token_name', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        return JitTokenName::lower($context, $args[0]);
    }
}

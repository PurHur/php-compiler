<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * token_name() — map token id to name (ext/tokenizer/tokenizer_data.c; issue #6940, #31407).
 *
 * php-src: ext/tokenizer/tokenizer.c — PHP_FUNCTION(token_name); stub int $id.
 */
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

        // Z_PARAM_LONG: caller strict_types → TypeError on null; else null→0 (#31407).
        $type = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'token_name',
            1,
            'id'
        );

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

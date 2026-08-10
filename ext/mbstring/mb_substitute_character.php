<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mb_substitute_character() — illegal-byte replacement (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_substitute_character extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substitute_character');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_substitute_character() expects at most 1 argument, %d given',
                $argc
            ));
        }
        // php-src Z_PARAM_STR_OR_LONG_OR_NULL — omitted/null selects getter
        // (mbstring.stub.php int|string|null $substchar = null); #29919.
        if (0 === $argc
            || Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type
        ) {
            if (null === $frame->returnVar) {
                return;
            }
            $value = MbstringState::substituteCharacter();
            if (\is_int($value)) {
                $frame->returnVar->int($value);
            } else {
                $frame->returnVar->string($value);
            }

            return;
        }
        // Setter must run even when the return is discarded (php-src; #25207).
        $result = MbstringState::substituteCharacter($frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_substitute_character() expects at most 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time omitted/null getter fold (php-src Z_PARAM_STR_OR_LONG_OR_NULL); setters stay VM-only.
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            $value = MbstringState::substituteCharacter();
            if (\is_int($value)) {
                return $context->getTypeFromString('int64')->constInt($value, false);
            }

            return $context->builder->load($context->constantStringFromString($value));
        }

        throw new \LogicException(
            'mb_substitute_character() JIT setter is not supported in this compiler build'
        );
    }
}

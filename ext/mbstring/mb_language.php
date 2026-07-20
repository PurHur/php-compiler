<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mb_language() — NLS language setting (php-src ext/mbstring/mbstring.c; #4636, #21538). */
final class mb_language extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_language');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_language() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src Z_PARAM_STR_OR_NULL — omitted/null selects getter (mbstring.stub.php ?string = null).
        if (0 === $argc
            || Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type
        ) {
            $frame->returnVar->string((string) MbstringState::language());

            return;
        }
        $language = VmMbstring::coerceLanguageArg($frame->calledArgs[0], 'mb_language', 0);
        $frame->returnVar->bool(MbstringState::language($language));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_language() expects at most 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time omitted/null getter fold (php-src Z_PARAM_STR_OR_NULL); setters stay VM-only.
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return $context->builder->load(
                $context->constantStringFromString((string) MbstringState::language())
            );
        }

        throw new \LogicException(
            'mb_language() JIT setter is not supported in this compiler build'
        );
    }
}

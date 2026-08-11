<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_regex_set_options() — get/set mbregex compile options (php-src ext/mbstring/php_mbregex.c; #4635).
 */
final class mb_regex_set_options extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_regex_set_options');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_regex_set_options() expects at most 1 argument, %d given',
                $argc
            ));
        }

        $options = null;
        if (1 === $argc) {
            $argVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $argVar->type) {
                $options = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[0],
                    'mb_regex_set_options',
                    0,
                    'options'
                );
            }
        }

        $previous = MbstringState::regexOptions($options);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($previous): void {
            $ret->string($previous);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_regex_set_options() is not lowered for JIT/AOT in this compiler build');
    }
}

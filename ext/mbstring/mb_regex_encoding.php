<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_regex_encoding() — get/set mbregex encoding (php-src ext/mbstring/php_mbregex.c; #4635, #30781 AOT).
 */
final class mb_regex_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_regex_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_regex_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }

        if (0 === $argc) {
            $result = MbstringState::regexEncoding();
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->string($result);
            });

            return;
        }

        $encodingVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $encodingVar->type) {
            $result = MbstringState::regexEncoding();
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->string($result);
            });

            return;
        }

        $encoding = VmMbstring::coerceEncodingString($encodingVar, 'mb_regex_encoding', 0);
        $ok = MbstringState::regexEncoding($encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbEregSearch::foldRegexEncoding($context, $args);
    }
}

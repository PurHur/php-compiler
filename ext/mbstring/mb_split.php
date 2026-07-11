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
 * mb_split() — multibyte regex split (php-src ext/mbstring/php_mbregex.c; #13367).
 */
final class mb_split extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_split() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_split',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_split',
            1,
            'string'
        );
        $limit = -1;
        if ($argc >= 3) {
            $limitVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \TypeError(sprintf(
                    'mb_split(): Argument #3 ($limit) must be of type int, %s given',
                    match ($limitVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_DOUBLE => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $limitVar->toObject()->class->name,
                        default => 'mixed',
                    }
                ));
            }
            $limit = $limitVar->toInt();
        }

        $result = VmMbstring::split($pattern, $string, $limit);
        if (false === $result) {
            if (null !== VmMbstring::mbSplitRegexCompileError($pattern)) {
                VmMbstring::warnMbSplitRegexFailure($frame, $pattern);
            }
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->array(MbstringState::hashTableFromStringList($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_split() is not lowered for JIT/AOT in this compiler build');
    }
}

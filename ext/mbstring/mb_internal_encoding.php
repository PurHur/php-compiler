<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_internal_encoding() — internal charset (php-src ext/mbstring/mbstring.c; #13376, #20014 AOT, #21538, #35221).
 *
 * JIT/AOT: NestedJIT get/set via {@see JitMbInternalEncoding} (runtime encoding + catchable ValueError).
 */
final class mb_internal_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_internal_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_internal_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src Z_PARAM_STRING_OR_NULL — omitted/null selects getter (mbstring.stub.php ?string = null).
        if (0 === $argc
            || Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type
        ) {
            $frame->returnVar->string(MbstringState::internalEncoding());

            return;
        }
        $encoding = VmMbstring::coerceMbEncodingNameArg(
            $frame->calledArgs[0],
            'mb_internal_encoding',
            0
        );
        $frame->returnVar->bool(MbstringState::setInternalEncoding($encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbInternalEncoding::invoke($context, $args);
    }
}

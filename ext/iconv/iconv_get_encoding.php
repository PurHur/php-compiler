<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv_get_encoding() — query iconv encoding settings (php-src ext/iconv/iconv.c; #6364).
 */
final class iconv_get_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_get_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_get_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $type = VmIconv::coerceEncodingArg($frame->calledArgs[0], 'iconv_get_encoding', 0, 'type');
            }
        }
        $result = IconvEncodingState::getEncoding($type);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            if (\is_array($result)) {
                $ret->array(IconvEncodingState::encodingArrayToHashTable($result));

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'iconv_get_encoding() is not lowered for JIT/AOT in this compiler build'
        );
    }
}

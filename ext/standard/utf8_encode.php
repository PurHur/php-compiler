<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** utf8_encode() — ISO-8859-1 to UTF-8 (php-src ext/standard/basic_functions.c). */
final class utf8_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('utf8_encode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('utf8_encode() requires exactly one argument in this compiler build');
        }
        Utf8EndecDeprecation::emitVm($frame, 'utf8_encode');
        $data = VmString::coerceTypedStringBuiltinArg(
            $frame->calledArgs[0],
            'utf8_encode',
            0,
            'string'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::utf8_encode($data))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('utf8_encode() requires exactly one argument in this compiler build');
        }

        return JitUtf8Latin1::encode(
            $context,
            JitStringBuiltinArg::lowerTypedString($context, $args[0], 'utf8_encode', 0, 'string')
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** convert_uuencode() — Unix-to-Unix encoding (php-src ext/standard/uuencode.c). */
final class convert_uuencode extends Internal
{
    public function __construct()
    {
        parent::__construct('convert_uuencode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('convert_uuencode() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('convert_uuencode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::convert_uuencode($data->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('convert_uuencode() requires exactly one argument in this compiler build');
        }

        return JitConvertUuencode::encode(
            $context,
            $this->jitString($context, $args[0], 'convert_uuencode() argument #1')
        );
    }
}

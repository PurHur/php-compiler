<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** base64_encode() — RFC 4648 standard alphabet (subset of PHP; native LLVM in JIT/AOT). */
final class base64_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_encode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('base64_encode() requires exactly one argument in this compiler build');
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('base64_encode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::base64_encode($data->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('base64_encode() requires exactly one argument in this compiler build');
        }
        $str = $this->jitString($context, $args[0], 'base64_encode() argument #1');

        return JitBase64Encode::encode($context, $str);
    }
}

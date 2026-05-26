<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** str_ireplace() — case-insensitive str_replace for strings (VM + JIT/AOT; libc strcasestr in JIT). */
final class str_ireplace extends Internal
{
    public function __construct()
    {
        parent::__construct('str_ireplace');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }
        $search = $frame->calledArgs[0]->resolveIndirect();
        $replace = $frame->calledArgs[1]->resolveIndirect();
        $subject = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $search->type
            || Variable::TYPE_STRING !== $replace->type
            || Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('str_ireplace() requires string arguments in this compiler build');
        }
        $frame->returnVar->string(VmString::strIreplace(
            $search->toString(),
            $replace->toString(),
            $subject->toString()
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }

        return JitStrIreplace::replace(
            $context,
            $this->jitString($context, $args[0], 'str_ireplace() search'),
            $this->jitString($context, $args[1], 'str_ireplace() replace'),
            $this->jitString($context, $args[2], 'str_ireplace() subject')
        );
    }
}

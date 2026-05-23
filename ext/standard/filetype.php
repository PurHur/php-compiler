<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** filetype() — VM via host; JIT/AOT via libc lstat(2) st_mode. */
final class filetype extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filetype() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('filetype() requires a string path in this compiler build');
        }
        $type = VmFs::fileType($v->toString());
        if (false === $type) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($type);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filetype() requires exactly one argument in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('filetype() requires a string path in this compiler build');
        }

        return JitFiletype::invoke($context, $context->helper->loadValue($args[0]));
    }
}

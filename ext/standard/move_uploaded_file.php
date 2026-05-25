<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** move_uploaded_file() — VM via VmFs; JIT/AOT via __compiler_move_uploaded_file (issue #2005). */
final class move_uploaded_file extends Internal
{
    public function __construct()
    {
        parent::__construct('move_uploaded_file');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('move_uploaded_file() requires exactly two arguments in this compiler build');
        }
        $fromVar = $frame->calledArgs[0]->resolveIndirect();
        $toVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $fromVar->type || Variable::TYPE_STRING !== $toVar->type) {
            throw new \LogicException('move_uploaded_file() requires string paths in this compiler build');
        }
        $frame->returnVar->bool(VmFs::moveUploadedFile($fromVar->toString(), $toVar->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('move_uploaded_file() requires exactly two arguments in this compiler build');
        }
        $a = $this->jitString($context, $args[0], 'move_uploaded_file() argument #1');
        $b = $this->jitString($context, $args[1], 'move_uploaded_file() argument #2');

        return JitMoveUploadedFile::invoke($context, $a, $b);
    }
}

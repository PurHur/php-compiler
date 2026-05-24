<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** pack() — binary string from format and values (VM via host PHP; JIT/AOT via __compiler_pack). */
final class pack extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('pack() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fmtVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('pack() format must be a string in this compiler build');
        }
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = VmJson::export($frame->calledArgs[$i]->resolveIndirect());
        }
        $frame->returnVar->string(VmPack::pack($fmtVar->toString(), $values));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitPack::pack($context, ...$args);
    }
}

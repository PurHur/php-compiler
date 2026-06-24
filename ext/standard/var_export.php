<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * var_export() subset for bootstrap/AOT (issue #4474 repro, #1492).
 */
final class var_export extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('var_export() requires one or two arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        $return = false;
        if (2 === $argc) {
            $retArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $retArg->type) {
                throw new \LogicException('var_export() return argument must be boolean in this compiler build');
            }
            $return = $retArg->toBool();
        }
        $vm = $frame->vmContext?->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_export() requires an active VM');
        }
        $exported = VmVarExport::formatVariable($vm, $v, 0, $frame);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($exported);
        } else {
            echo $exported;
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVarExport::invoke($context, ...$args);
    }
}

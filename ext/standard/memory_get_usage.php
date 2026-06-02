<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** memory_get_usage() — current memory usage bytes (issue #3134). */
final class memory_get_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_get_usage');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmMemory::getUsage(self::resolveRealUsage($frame)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMemory::getUsage($context, $args[0] ?? null);
    }

    private static function resolveRealUsage(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('memory_get_usage() accepts at most one argument');
        }
        if (0 === $argc) {
            return false;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $arg->type) {
            throw new \LogicException('memory_get_usage() real_usage must be boolean in this compiler build');
        }

        return $arg->toBool();
    }
}

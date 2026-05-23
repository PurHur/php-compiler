<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sys_get_temp_dir() — host temp directory (VM host; JIT/AOT embeds compile-time path). */
final class sys_get_temp_dir extends Internal
{
    public function __construct()
    {
        parent::__construct('sys_get_temp_dir');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('sys_get_temp_dir() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmFs::tempDir());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('sys_get_temp_dir() takes no arguments');
        }

        return $context->builder->load(
            $context->constantStringFromString(VmFs::tempDir())
        );
    }
}

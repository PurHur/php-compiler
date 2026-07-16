<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal init-safe process environ mirror into native __hashtable__* (#19157, #19555). */
final class phpc_native_environ_mirror_into_ht extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_environ_mirror_into_ht');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_environ_mirror_into_ht() is JIT-only (#19157)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_native_environ_mirror_into_ht() expects 1 argument');
        }
        JitEnvironMirrorKernel::mirrorIntoHashtable($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}

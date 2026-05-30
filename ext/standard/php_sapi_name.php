<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php_sapi_name() — active SAPI identifier (ext/standard/info.c parity, issue #3174). */
final class php_sapi_name extends Internal
{
    public function __construct()
    {
        parent::__construct('php_sapi_name');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('php_sapi_name() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmInfo::php_sapi_name());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('php_sapi_name() takes no arguments');
        }

        return JitInfo::php_sapi_name($context);
    }
}

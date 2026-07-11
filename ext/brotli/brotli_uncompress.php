<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** brotli_uncompress() — libbrotli via FFI (kjdev/php-ext-brotli; issue #6814). */
final class brotli_uncompress extends Internal
{
    public function __construct()
    {
        parent::__construct('brotli_uncompress');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('brotli_uncompress() expects exactly one argument in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'brotli_uncompress', 0, 'data');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBrotliNative::uncompress($data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('brotli_uncompress() expects exactly one argument in this compiler build');
        }

        return JitBrotli::uncompress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'brotli_uncompress', 0, 'data')
        );
    }
}

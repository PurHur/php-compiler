<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** igbinary_serialize() — binary serialize (php-src ext/igbinary/igbinary.c; #6573). */
final class igbinary_serialize extends Internal
{
    public function __construct()
    {
        parent::__construct('igbinary_serialize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'igbinary_serialize', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $packed = VmIgbinary::serialize($frame->calledArgs[0]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
        $frame->returnVar->string($packed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('igbinary_serialize() is not implemented for JIT in this compiler build (issue #6573)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** igbinary_unpack() — alias of igbinary_unserialize() (php-src ext/igbinary/igbinary.c; #6573). */
final class igbinary_unpack extends Internal
{
    public function __construct()
    {
        parent::__construct('igbinary_unpack');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'igbinary_unpack', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'igbinary_unpack',
            0,
            'data'
        );
        $decoded = VmIgbinary::unserialize($data, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('igbinary_unpack() is not implemented for JIT in this compiler build (issue #6573)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPLLVM\Value;

/** mysqli_init() — allocate an unconnected mysqli handle (php-src ext/mysqli/mysqli.c; #21803). */
final class mysqli_init extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_init');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_init requires VM context');
        $class = $ctx->classes[VmMysqli::CLASS_LC]
            ?? throw new \LogicException('mysqli class not registered');
        $entry = new ObjectEntry($class);
        VmMysqli::initializeObject($ctx, $entry);
        $frame->returnVar->object($entry);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_init() is not implemented for JIT (issue #21803)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** Uncaught generator throw bubbling to caller (#167, #13418). */
final class GeneratorUncaughtThrow extends \Exception
{
    public function __construct(
        public readonly Variable $thrown,
        public readonly ?Frame $throwFrame = null,
    ) {
        parent::__construct('Generator uncaught throw');
    }
}

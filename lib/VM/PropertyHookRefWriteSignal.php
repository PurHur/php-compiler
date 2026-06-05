<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** Resume VM at a catch frame after property hook ref write (#6426). */
final class PropertyHookRefWriteSignal extends \Exception
{
    public function __construct(public readonly Frame $catchFrame)
    {
        parent::__construct('Property hook reference write');
    }
}

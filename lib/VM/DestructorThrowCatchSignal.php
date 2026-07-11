<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** Resume VM at a catch frame after __destruct() throw on an isolated run stack (#12070). */
final class DestructorThrowCatchSignal extends \Exception
{
    public function __construct(public readonly Frame $catchFrame)
    {
        parent::__construct('__destruct() throw');
    }
}

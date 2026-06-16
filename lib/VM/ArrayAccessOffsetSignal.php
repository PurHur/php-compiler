<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** Resume VM at a catch frame after deferred ArrayAccess offset read/write (#8949). */
final class ArrayAccessOffsetSignal extends \Exception
{
    public function __construct(public readonly Frame $catchFrame)
    {
        parent::__construct('ArrayAccess offset operation');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class TryCatchState
{
    /** @var list<TryCatchHandler> */
    public array $handlerStack = [];

    /** @var array<int, TryCatchHandler> */
    public array $mergeHandlers = [];
}

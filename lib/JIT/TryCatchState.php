<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class TryCatchState
{
    /** @var list<TryCatchHandler> */
    public array $handlerStack = [];

    /** @var array<int, TryCatchHandler> */
    public array $mergeHandlers = [];

    /** Fresh stack for each queued CFG function (#3012, #3027). */
    public function reset(): void
    {
        $this->handlerStack = [];
        $this->mergeHandlers = [];
    }
}

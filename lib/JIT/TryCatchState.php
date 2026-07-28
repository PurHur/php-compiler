<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class TryCatchState
{
    /** @var list<TryCatchHandler> */
    public array $handlerStack = [];

    /** @var array<int, TryCatchHandler> */
    public array $mergeHandlers = [];

    /**
     * Handlers with finally for return-through-finally (#4246 / #24105).
     * Survives catch-arm detach from {@see handlerStack} so `return` in catch still runs finally.
     *
     * @var list<TryCatchHandler>
     */
    public array $returnFinallyStack = [];

    /** Fresh try/catch stack for a JIT Context (avoids `new TryCatchState` in Context.php — #3027). */
    public static function create(): self
    {
        $state = new self();
        $state->reset();

        return $state;
    }

    /** Fresh stack for each queued CFG function (#3012, #3027). */
    public function reset(): void
    {
        $this->handlerStack = [];
        $this->mergeHandlers = [];
        $this->returnFinallyStack = [];
    }
}

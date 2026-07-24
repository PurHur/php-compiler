<?php

declare(strict_types=1);

namespace PHPCfg\Op\Terminal;

use PHPCfg\Op\Terminal;

/**
 * declare(ticks=N) — runtime tick interval directive (#3343, #22840).
 *
 * When $scoped is true, the directive wraps a braced declare body and must
 * push/pop the previous interval (ENTER/LEAVE) even if ticks were already active.
 */
class SetTickInterval extends Terminal
{
    public int $interval;

    /** True for declare(ticks=N) { … } braced form (#22840). */
    public bool $scoped;

    public function __construct(int $interval, array $attributes = [], bool $scoped = false)
    {
        parent::__construct($attributes);
        $this->interval = $interval;
        $this->scoped = $scoped;
    }

    public function getVariableNames(): array
    {
        return [];
    }
}

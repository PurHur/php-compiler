<?php

declare(strict_types=1);

namespace PHPCfg\Op\Terminal;

use PHPCfg\Op\Terminal;

/**
 * declare(ticks=N) — runtime tick interval directive (#3343).
 */
class SetTickInterval extends Terminal
{
    public int $interval;

    public function __construct(int $interval, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->interval = $interval;
    }

    public function getVariableNames(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace PHPCfg\Op\Terminal;

use PHPCfg\Op\Terminal;

/**
 * End braced declare(ticks=N) { … } — restore previous interval (#22840).
 */
class LeaveTickInterval extends Terminal
{
    public function getVariableNames(): array
    {
        return [];
    }
}

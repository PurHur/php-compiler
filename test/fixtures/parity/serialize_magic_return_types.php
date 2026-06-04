<?php
// Issue #5384 repro: invalid __sleep/__wakeup return types must fail at compile time.
class SleepBad {
    public function __sleep(): int
    {
        return 0;
    }
}
serialize(new SleepBad());

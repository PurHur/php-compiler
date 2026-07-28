<?php
// Repro #24086 — AOT must honor non-empty array property literal defaults.
class C
{
    private array $c = [1, 2];

    public function m(): int
    {
        return count($this->c);
    }
}

echo (new C)->m(), "\n";

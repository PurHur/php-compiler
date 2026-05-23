<?php

declare(strict_types=1);

/**
 * Minimal SplObjectStorage foreach + count for Block::getFrame (issue #816).
 */

class MiniBlock {
    private \SplObjectStorage $scope;

    public function __construct() {
        $this->scope = new \SplObjectStorage();
    }

    public function walk(): int {
        $n = $this->scope->count();
        foreach ($this->scope as $op) {
            $pos = $this->scope[$op];
            $n += (int) $pos;
        }

        return $n;
    }
}

$block = new MiniBlock();
echo (string) $block->walk(), "\n";

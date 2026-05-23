<?php

declare(strict_types=1);

/**
 * getFrame loop body: SplObjectStorage count, foreach, dim fetch, contains.
 */

class MiniBlock {
    private \SplObjectStorage $scope;
    private \SplObjectStorage $args;

    public function __construct() {
        $this->scope = new \SplObjectStorage();
        $this->args = new \SplObjectStorage();
    }

    public function build(): int {
        $scope = [];
        $scopeSize = $this->scope->count();
        foreach ($this->scope as $op) {
            $pos = $this->scope[$op];
            if ($this->args->contains($op)) {
                $scope[$pos] = $pos;
            } else {
                $scope[$pos] = $pos + 1;
            }
        }

        return $scopeSize + count($scope);
    }
}

$block = new MiniBlock();
echo (string) $block->build(), "\n";

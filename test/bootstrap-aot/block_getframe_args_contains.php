<?php

declare(strict_types=1);

/**
 * SplObjectStorage::contains for Block::getFrame args check (issue #816).
 */

class MiniBlock {
    public \SplObjectStorage $args;

    public function __construct() {
        $this->args = new \SplObjectStorage();
    }

    public function hasArg(object $op): bool {
        return $this->args->contains($op);
    }
}

$block = new MiniBlock();
$key = new stdClass();
echo $block->hasArg($key) ? "0\n" : "1\n";

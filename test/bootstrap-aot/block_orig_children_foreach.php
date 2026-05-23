<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: foreach over $this->orig->children (Block::scriptPath pattern, issue #848).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;

class MiniHost
{
    public ?CfgBlock $orig;

    public function __construct(?CfgBlock $orig)
    {
        $this->orig = $orig;
    }

    public function walk(): int
    {
        $n = 0;
        if (null !== $this->orig) {
            foreach ($this->orig->children as $child) {
                if ($child instanceof Op) {
                    ++$n;
                }
            }
        }

        return $n;
    }
}

$cfg = new CfgBlock();
$host = new MiniHost($cfg);
echo (string) $host->walk();

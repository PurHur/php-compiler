<?php

declare(strict_types=1);

namespace PHPCfg\Op\Stmt;

use PHPCfg\Op\Stmt;
use PHPCfg\Operand;

class TraitUse extends Stmt
{
    /** @var Operand[] */
    public $traits;

    /** @var \PhpParser\Node\Stmt\TraitUseAdaptation[] */
    public $adaptations;

    public function __construct(array $traits, array $adaptations = [], array $attributes = [])
    {
        parent::__construct($attributes);
        $this->traits = $traits;
        $this->adaptations = $adaptations;
    }

    public function getVariableNames(): array
    {
        return [];
    }
}

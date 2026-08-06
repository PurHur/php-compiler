<?php

declare(strict_types=1);

namespace PHPCfg\Op\Stmt;

use PHPCfg\Block;
use PHPCfg\Operand;
use PHPCfg\Op\Stmt;

class TryCatch extends Stmt
{
    /** @var Block */
    public $try;

    /** @var Block[] */
    public $catches;

    /** @var Block|null */
    public $finally;

    /** @var Block */
    public $end;

    /** @var list<list<string>> exception type names per catch (union = multiple per catch, issue #1362; intersection = single `A&B` member, #28205) */
    public array $catchTypes;

    /** @var list<Operand|null> catch variable operands per catch */
    public array $catchVars;

    /**
     * @param Block[] $catches
     * @param list<list<string>> $catchTypes
     * @param list<Operand|null> $catchVars
     */
    public function __construct(
        Block $try,
        array $catches,
        ?Block $finally,
        Block $end,
        array $catchTypes,
        array $catchVars,
        array $attributes = []
    ) {
        parent::__construct($attributes);
        $this->try = $try;
        $this->catches = $catches;
        $this->finally = $finally;
        $this->end = $end;
        $this->catchTypes = $catchTypes;
        $this->catchVars = $catchVars;
    }

    public function getSubBlocks(): array
    {
        return ['try', 'catches', 'finally', 'end'];
    }

    public function getType(): string
    {
        return 'Stmt_TryCatch';
    }
}

<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: foreach $cfg->children + FuncCall match (ConstStringFolder::parseDeployPathCall).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

function findCall(CfgBlock $cfg, Operand $operand): int
{
    foreach ($cfg->children as $child) {
        if ($child instanceof Op\Expr\FuncCall && $child->result === $operand) {
            return 1;
        }
    }

    return 0;
}

echo (string) findCall(new CfgBlock(), new Operand\Literal(null));

<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: ConstStringFolder isset/?? on deploy-path CFG walk (#816).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

function findCall(CfgBlock $cfg, Operand $operand): ?Op\Expr\FuncCall
{
    foreach ($cfg->children as $child) {
        if ($child instanceof Op\Expr\FuncCall && $child->result === $operand) {
            return $child;
        }
    }

    return null;
}

$cfg = new CfgBlock();
$operand = new Operand\Literal(null);
echo null === findCall($cfg, $operand) ? "0\n" : "1\n";

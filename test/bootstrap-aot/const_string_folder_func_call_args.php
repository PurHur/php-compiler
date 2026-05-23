<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: FuncCall $call->args[n] (ConstStringFolder::foldCallArgString / foldDeployPathConcat).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

function literalFromCallArg(Op\Expr\FuncCall $call, int $index): ?string
{
    if (!isset($call->args[$index])) {
        return null;
    }
    $operand = $call->args[$index];
    if ($operand instanceof Operand\Literal && is_string($operand->value)) {
        return $operand->value;
    }

    return null;
}

$cfg = new CfgBlock();
$call = new Op\Expr\FuncCall(
    new Operand\Literal('phpc_deploy_path'),
    [
        new Operand\Literal('templates'),
        new Operand\Literal('fallback'),
    ]
);
echo (null !== literalFromCallArg($call, 0) ? '1' : '0')."\n";

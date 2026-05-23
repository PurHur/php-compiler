<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: ConstStringFolder::parseDeployPathCall ?? / isset patterns (#816).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

final class DeployPathProbe
{
    private static function findFuncCallForOperand(CfgBlock $cfg, Operand $operand): ?Op\Expr\FuncCall
    {
        if ($operand instanceof Op\Expr\FuncCall) {
            return $operand;
        }
        if ($operand instanceof Operand\Temporary && $operand->original instanceof Op\Expr\FuncCall) {
            return $operand->original;
        }
        foreach ($cfg->children as $child) {
            if ($child instanceof Op\Expr\FuncCall && $child->result === $operand) {
                return $child;
            }
        }

        return null;
    }

    private static function parseDeployPathCall(CfgBlock $cfg, Operand $operand, string $sourceFile = ''): ?array
    {
        $call = self::findFuncCallForOperand($cfg, $operand);
        if (null === $call) {
            return null;
        }
        $name = self::literalStringValue($call->name) ?? self::fold($call->name, $sourceFile);
        if ('phpc_deploy_path' !== $name || 2 !== count($call->args)) {
            return null;
        }
        $rel = self::literalStringValue($call->args[0]) ?? self::fold($call->args[0], $sourceFile);
        $fallback = self::literalStringValue($call->args[1]) ?? self::fold($call->args[1], $sourceFile);
        if (null === $rel || null === $fallback) {
            return null;
        }

        return [$rel, $fallback];
    }

    private static function fold(Operand $operand, string $sourceFile = ''): ?string
    {
        return self::literalStringValue($operand);
    }

    private static function literalStringValue(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }

        return null;
    }

    public static function run(CfgBlock $cfg): int
    {
        $operand = new Operand\Literal(null);
        $operand->type = \PHPTypes\Type::null();

        return null === self::parseDeployPathCall($cfg, $operand) ? 0 : 1;
    }
}

echo "0\n";

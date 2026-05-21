<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

/**
 * Fold compile-time string expressions (literals and concat) for include paths.
 *
 * @see https://github.com/PurHur/php-compiler/issues/85
 * @see https://github.com/PurHur/php-compiler/issues/54
 */
final class ConstStringFolder
{
    public static function fold(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if ($operand instanceof Operand\Temporary) {
            $original = $operand->original;
            if ($original instanceof Op\Expr\BinaryOp\Concat) {
                return self::foldConcat($original);
            }
            if ($original instanceof Operand\Literal && is_string($original->value)) {
                return $original->value;
            }
        }
        return null;
    }

    public static function foldConcat(Op\Expr\BinaryOp\Concat $concat): ?string
    {
        $left = self::fold($concat->left);
        $right = self::fold($concat->right);
        if (null !== $left && null !== $right) {
            return $left.$right;
        }

        return null;
    }

    /**
     * Fold include/require path operands (often a Concat temp with cleared original).
     */
    public static function foldForInclude(CfgBlock $cfg, Operand $operand): ?string
    {
        $direct = self::fold($operand);
        if (null !== $direct) {
            return $direct;
        }
        if ($operand instanceof Operand\Temporary) {
            foreach ($cfg->children as $child) {
                if ($child instanceof Op\Expr\BinaryOp\Concat && $child->result === $operand) {
                    return self::foldConcat($child);
                }
            }
        }

        return null;
    }
}

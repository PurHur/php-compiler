<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op;

/**
 * Fold compile-time string expressions (literals and concat) for include paths.
 *
 * Recognizes `__DIR__` (parser lowers to a dirname literal) concatenated with a
 * literal suffix when the including file path is known.
 *
 * @see https://github.com/PurHur/php-compiler/issues/85
 * @see https://github.com/PurHur/php-compiler/issues/54
 * @see https://github.com/PurHur/php-compiler/issues/462
 */
final class ConstStringFolder
{
    public static function fold(Operand $operand, string $sourceFile = ''): ?string
    {
        $literal = self::literalStringValue($operand);
        if (null !== $literal) {
            return $literal;
        }
        if ($operand instanceof Operand\Temporary) {
            $original = $operand->original;
            if ($original instanceof Op\Expr\BinaryOp\Concat) {
                return self::foldConcat($original, $sourceFile);
            }
            if ($original instanceof Operand\Literal && is_string($original->value)) {
                return $original->value;
            }
        }

        return null;
    }

    public static function foldConcat(Op\Expr\BinaryOp\Concat $concat, string $sourceFile = ''): ?string
    {
        $left = self::fold($concat->left, $sourceFile);
        $right = self::fold($concat->right, $sourceFile);
        if (null !== $left && null !== $right) {
            return $left.$right;
        }
        if ('' === $sourceFile) {
            return null;
        }
        $dir = self::sourceDir($sourceFile);
        $leftLit = self::literalStringValue($concat->left);
        $rightLit = self::literalStringValue($concat->right);
        if (null !== $leftLit && null !== $rightLit) {
            return $leftLit.$rightLit;
        }
        if (null !== $rightLit && $rightLit === $dir && null !== $leftLit) {
            return $leftLit.$dir;
        }
        if (null !== $leftLit && $leftLit === $dir && null !== $rightLit) {
            return $dir.$rightLit;
        }
        $deploy = self::foldDeployPathConcat($concat->left, $concat->right);
        if (null !== $deploy) {
            return $deploy;
        }

        return null;
    }

    /**
     * @return ?array{0: string, 1: string} rel, fallback dir
     */
    private static function parseDeployPathCall(Operand $operand): ?array
    {
        $call = null;
        if ($operand instanceof Operand\Temporary && $operand->original instanceof Op\Expr\FuncCall) {
            $call = $operand->original;
        } elseif ($operand instanceof Op\Expr\FuncCall) {
            $call = $operand;
        }
        if (null === $call) {
            return null;
        }
        $name = self::literalStringValue($call->name);
        if ('phpc_deploy_path' !== $name || 2 !== count($call->args)) {
            return null;
        }
        $rel = self::literalStringValue($call->args[0]);
        $fallback = self::literalStringValue($call->args[1]);
        if (null === $rel || null === $fallback) {
            return null;
        }

        return [$rel, $fallback];
    }

    private static function foldDeployPathConcat(Operand $left, Operand $right): ?string
    {
        $suffix = self::literalStringValue($right);
        if (null === $suffix) {
            return null;
        }
        $parsed = self::parseDeployPathCall($left);
        if (null === $parsed) {
            return null;
        }

        return DeployRoot::resolvePathWithSuffix($parsed[0], $parsed[1], $suffix);
    }

    /**
     * Fold include/require path operands (often a Concat temp with cleared original).
     */
    public static function foldForInclude(CfgBlock $cfg, Operand $operand, string $sourceFile = ''): ?string
    {
        $direct = self::fold($operand, $sourceFile);
        if (null !== $direct) {
            return $direct;
        }
        if ($operand instanceof Operand\Temporary) {
            foreach ($cfg->children as $child) {
                if ($child instanceof Op\Expr\BinaryOp\Concat && $child->result === $operand) {
                    return self::foldConcat($child, $sourceFile);
                }
            }
        }

        return null;
    }

    private static function literalStringValue(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }

        return null;
    }

    private static function sourceDir(string $sourceFile): string
    {
        $resolved = realpath($sourceFile);
        if (false !== $resolved) {
            return dirname($resolved);
        }

        return dirname($sourceFile);
    }
}

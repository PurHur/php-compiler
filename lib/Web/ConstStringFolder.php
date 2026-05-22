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
        $magic = self::magicScriptConstValue($operand, $sourceFile, null);
        if (null !== $magic) {
            return $magic;
        }
        $literal = self::literalStringValue($operand);
        if (null !== $literal) {
            return $literal;
        }
        if ($operand instanceof Operand\Temporary) {
            $original = $operand->original;
            if ($original instanceof Op\Expr\BinaryOp\Concat) {
                return self::foldConcat($original, $sourceFile, null);
            }
            if ($original instanceof Operand\Literal && is_string($original->value)) {
                return $original->value;
            }
        }

        return null;
    }

    public static function foldConcat(Op\Expr\BinaryOp\Concat $concat, string $sourceFile = '', ?CfgBlock $cfg = null): ?string
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
        $leftMagic = self::magicScriptConstValue($concat->left, $sourceFile, $cfg);
        if (null !== $leftMagic && null !== $rightLit) {
            return $leftMagic.$rightLit;
        }
        if (null !== $rightLit && $rightLit === $dir && null !== $leftLit) {
            return $leftLit.$dir;
        }
        if (null !== $leftLit && $leftLit === $dir && null !== $rightLit) {
            return $dir.$rightLit;
        }
        $deploy = self::foldDeployPathConcat($concat->left, $concat->right, $sourceFile);
        if (null !== $deploy) {
            return $deploy;
        }

        return null;
    }

    /**
     * @return ?array{0: string, 1: string} rel, fallback dir
     */
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

    private static function parseDeployPathCallFromOperand(Operand $operand, string $sourceFile = ''): ?array
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
        foreach (self::collectFuncCalls($cfg) as $call) {
            if ($call->result === $operand) {
                return $call;
            }
        }

        return null;
    }

    /**
     * @return list<Op\Expr\FuncCall>
     */
    private static function collectFuncCalls(CfgBlock $block): array
    {
        $calls = [];
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\FuncCall) {
                $calls[] = $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    foreach (self::collectFuncCalls($sub) as $nested) {
                        $calls[] = $nested;
                    }
                }
            }
        }

        return $calls;
    }

    private static function foldDeployPathConcat(Operand $left, Operand $right, string $sourceFile = ''): ?string
    {
        $suffix = self::literalStringValue($right);
        if (null === $suffix) {
            return null;
        }
        // foldDeployPathConcat is used without cfg from foldConcat; only literal deploy calls fold here.
        $parsed = self::parseDeployPathCallFromOperand($left, $sourceFile);
        if (null === $parsed) {
            return null;
        }

        return DeployRoot::resolvePathWithSuffix($parsed[0], $parsed[1], $suffix);
    }

    /**
     * Recognize include phpc_deploy_path('rel', fallback) . '/suffix' for runtime deploy resolution (#623).
     *
     * @return ?array{rel: string, fallback: string, suffix: string, compile: ?string}
     */
    public static function tryParseDeployInclude(CfgBlock $cfg, Operand $operand, string $sourceFile = ''): ?array
    {
        $concat = self::findConcatForOperand($cfg, $operand);
        if (null !== $concat) {
            return self::specFromDeployConcat($concat, $cfg, $sourceFile);
        }
        foreach ($cfg->children as $child) {
            if (!$child instanceof Op\Expr\Include_ || $child->expr !== $operand) {
                continue;
            }
            $specs = [];
            foreach (self::collectConcatOps($cfg) as $candidate) {
                $spec = self::specFromDeployConcat($candidate, $cfg, $sourceFile);
                if (null !== $spec) {
                    $specs[] = $spec;
                }
            }
            if (1 === count($specs)) {
                return $specs[0];
            }

            return null;
        }

        return null;
    }

    /**
     * @return ?array{rel: string, fallback: string, suffix: string, compile: ?string}
     */
    private static function specFromDeployConcat(
        Op\Expr\BinaryOp\Concat $concat,
        CfgBlock $cfg,
        string $sourceFile = ''
    ): ?array {
        $suffix = self::literalStringValue($concat->right);
        if (null === $suffix) {
            return null;
        }
        $parsed = self::parseDeployPathCall($cfg, $concat->left, $sourceFile);
        if (null === $parsed) {
            return null;
        }
        [$rel, $fallback] = $parsed;

        return [
            'rel' => $rel,
            'fallback' => $fallback,
            'suffix' => $suffix,
            'compile' => DeployRoot::resolvePathWithSuffix($rel, $fallback, $suffix),
        ];
    }

    /**
     * Fold include/require path operands (often a Concat temp with cleared original).
     */
    public static function foldForInclude(CfgBlock $cfg, Operand $operand, string $sourceFile = ''): ?string
    {
        if (null !== self::tryParseDeployInclude($cfg, $operand, $sourceFile)) {
            return null;
        }
        $direct = self::fold($operand, $sourceFile);
        if (null !== $direct) {
            return $direct;
        }
        if ($operand instanceof Operand\Temporary) {
            $concat = self::findConcatForOperand($cfg, $operand);
            if (null !== $concat) {
                return self::foldConcat($concat, $sourceFile, $cfg);
            }
        }

        return null;
    }

    private static function findConcatForOperand(CfgBlock $cfg, Operand $operand): ?Op\Expr\BinaryOp\Concat
    {
        if ($operand instanceof Op\Expr\BinaryOp\Concat) {
            return $operand;
        }
        if ($operand instanceof Operand\Temporary && $operand->original instanceof Op\Expr\BinaryOp\Concat) {
            return $operand->original;
        }
        foreach (self::collectConcatOps($cfg) as $concat) {
            if ($concat->result === $operand) {
                return $concat;
            }
        }

        return null;
    }

    /**
     * @return list<Op\Expr\BinaryOp\Concat>
     */
    private static function collectConcatOps(CfgBlock $block): array
    {
        $concats = [];
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\BinaryOp\Concat) {
                $concats[] = $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    foreach (self::collectConcatOps($sub) as $nested) {
                        $concats[] = $nested;
                    }
                }
            }
        }

        return $concats;
    }

    private static function literalStringValue(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }
        $magic = self::magicScriptConstValue($operand);
        if (null !== $magic) {
            return $magic;
        }

        return null;
    }

    private static function magicScriptConstValue(Operand $operand, string $sourceFile = '', ?CfgBlock $cfg = null): ?string
    {
        $magic = null;
        if ($operand instanceof Op\Expr\MagicScriptConst) {
            $magic = $operand;
        } elseif ($operand instanceof Operand\Temporary && $operand->original instanceof Op\Expr\MagicScriptConst) {
            $magic = $operand->original;
        } elseif (null !== $cfg) {
            $magic = self::findMagicScriptConstForOperand($cfg, $operand);
        }
        if (null === $magic || '' === $sourceFile) {
            return null;
        }
        if (Op\Expr\MagicScriptConst::KIND_DIR === $magic->kind) {
            return self::sourceDir($sourceFile);
        }
        if (Op\Expr\MagicScriptConst::KIND_FILE === $magic->kind) {
            $resolved = realpath($sourceFile);

            return false !== $resolved ? $resolved : $sourceFile;
        }

        return null;
    }

    private static function findMagicScriptConstForOperand(CfgBlock $cfg, Operand $operand): ?Op\Expr\MagicScriptConst
    {
        foreach (self::collectMagicScriptConsts($cfg) as $magic) {
            if ($magic->result === $operand) {
                return $magic;
            }
        }

        return null;
    }

    /**
     * @return list<Op\Expr\MagicScriptConst>
     */
    private static function collectMagicScriptConsts(CfgBlock $block): array
    {
        $found = [];
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\MagicScriptConst) {
                $found[] = $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    foreach (self::collectMagicScriptConsts($sub) as $nested) {
                        $found[] = $nested;
                    }
                }
            }
        }

        return $found;
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

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
        $deploy = self::foldDeployPathConcat($concat->left, $concat->right, $sourceFile, $cfg);
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
        if ('phpc_deploy_path' !== $name || !self::funcCallHasArity($call, 2)) {
            return null;
        }
        $rel = self::foldCallArgString($cfg, $call->args[0], $sourceFile);
        $fallback = self::foldCallArgString($cfg, $call->args[1], $sourceFile);
        if (null === $rel || null === $fallback) {
            return null;
        }

        return [$rel, $fallback];
    }

    private static function parseDeployPathCallFromOperand(
        Operand $operand,
        string $sourceFile = '',
        ?CfgBlock $cfg = null
    ): ?array {
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
        if ('phpc_deploy_path' !== $name || !self::funcCallHasArity($call, 2)) {
            return null;
        }
        $rel = self::foldCallArgString($cfg, $call->args[0], $sourceFile);
        $fallback = self::foldCallArgString($cfg, $call->args[1], $sourceFile);
        if (null === $rel || null === $fallback) {
            return null;
        }

        return [$rel, $fallback];
    }

    private static function funcCallHasArity(Op\Expr\FuncCall $call, int $expected): bool
    {
        if (!isset($call->args[$expected - 1])) {
            return false;
        }

        return !isset($call->args[$expected]);
    }

    private static function foldCallArgString(?CfgBlock $cfg, Operand $operand, string $sourceFile = ''): ?string
    {
        $literal = self::literalStringValue($operand);
        if (null !== $literal) {
            return $literal;
        }
        $folded = self::fold($operand, $sourceFile);
        if (null !== $folded) {
            return $folded;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Concat) {
            return self::foldConcat($operand, $sourceFile, $cfg);
        }
        if (null !== $cfg) {
            $concat = self::findConcatForOperand($cfg, $operand);
            if (null !== $concat) {
                return self::foldConcat($concat, $sourceFile, $cfg);
            }
        }

        return null;
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

        return self::findFuncCallInBlockTree($cfg, $operand);
    }

    private static function findFuncCallInBlockTree(CfgBlock $block, Operand $operand): ?Op\Expr\FuncCall
    {
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\FuncCall && $child->result === $operand) {
                return $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    $found = self::findFuncCallInBlockTree($sub, $operand);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private static function foldDeployPathConcat(
        Operand $left,
        Operand $right,
        string $sourceFile = '',
        ?CfgBlock $cfg = null
    ): ?string {
        $suffix = self::literalStringValue($right);
        if (null === $suffix) {
            return null;
        }
        $call = null;
        if ($left instanceof Operand\Temporary && $left->original instanceof Op\Expr\FuncCall) {
            $call = $left->original;
        } elseif ($left instanceof Op\Expr\FuncCall) {
            $call = $left;
        }
        if (null === $call) {
            return null;
        }
        $name = self::literalStringValue($call->name) ?? self::fold($call->name, $sourceFile);
        if ('phpc_deploy_path' !== $name || !self::funcCallHasArity($call, 2)) {
            return null;
        }
        $rel = self::foldCallArgString($cfg, $call->args[0], $sourceFile);
        $fallback = self::foldCallArgString($cfg, $call->args[1], $sourceFile);
        if (null === $rel || null === $fallback) {
            return null;
        }

        return DeployRoot::resolvePathWithSuffix($rel, $fallback, $suffix);
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
            $spec = self::soleDeploySpecFromCfg($cfg, $sourceFile);
            if (null !== $spec) {
                return $spec;
            }

            return null;
        }

        return null;
    }

    /**
     * @return ?array{rel: string, fallback: string, suffix: string, compile: ?string}
     */
    private static function soleDeploySpecFromCfg(CfgBlock $cfg, string $sourceFile): ?array
    {
        $found = null;
        $ambiguous = false;
        self::walkDeploySpecs($cfg, $cfg, $sourceFile, $found, $ambiguous);

        return $ambiguous ? null : $found;
    }

    /**
     * @param ?array{rel: string, fallback: string, suffix: string, compile: ?string} $found
     */
    private static function walkDeploySpecs(
        CfgBlock $block,
        CfgBlock $cfg,
        string $sourceFile,
        ?array &$found,
        bool &$ambiguous
    ): void {
        if ($ambiguous) {
            return;
        }
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\BinaryOp\Concat) {
                $spec = self::specFromDeployConcat($child, $cfg, $sourceFile);
                if (null !== $spec) {
                    if (null !== $found) {
                        $ambiguous = true;

                        return;
                    }
                    $found = $spec;
                }
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    self::walkDeploySpecs($sub, $cfg, $sourceFile, $found, $ambiguous);
                    if ($ambiguous) {
                        return;
                    }
                }
            }
        }
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
        $compile = DeployRoot::resolvePathWithSuffix($rel, $fallback, $suffix);

        return [
            'rel' => $rel,
            'fallback' => $fallback,
            'suffix' => $suffix,
            'compile' => null !== $compile ? $compile : '',
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
        return self::findConcatInBlockTree($cfg, $operand);
    }

    private static function findConcatInBlockTree(CfgBlock $block, Operand $operand): ?Op\Expr\BinaryOp\Concat
    {
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\BinaryOp\Concat && $child->result === $operand) {
                return $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    $found = self::findConcatInBlockTree($sub, $operand);
                    if (null !== $found) {
                        return $found;
                    }
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
        if (1 === $magic->kind) {
            return self::sourceDir($sourceFile);
        }
        if (2 === $magic->kind) {
            $resolved = realpath($sourceFile);

            return false !== $resolved ? $resolved : $sourceFile;
        }

        return null;
    }

    private static function findMagicScriptConstForOperand(CfgBlock $cfg, Operand $operand): ?Op\Expr\MagicScriptConst
    {
        return self::findMagicScriptConstInBlockTree($cfg, $operand);
    }

    private static function findMagicScriptConstInBlockTree(
        CfgBlock $block,
        Operand $operand
    ): ?Op\Expr\MagicScriptConst {
        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\MagicScriptConst && $child->result === $operand) {
                return $child;
            }
            foreach ($child->getSubBlocks() as $sub) {
                if ($sub instanceof CfgBlock) {
                    $found = self::findMagicScriptConstInBlockTree($sub, $operand);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private static function sourceDir(string $sourceFile): string
    {
        $resolved = realpath($sourceFile);
        if (is_string($resolved)) {
            return dirname($resolved);
        }

        return dirname($sourceFile);
    }
}

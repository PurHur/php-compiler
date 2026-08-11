<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\OpCode;

/**
 * PHP 8.4+ Zend closure/arrow display names for TypeError / Reflection / __debugInfo (#30076).
 *
 * php-src: Zend/zend_compile.c zend_begin_func_decl — {@code {closure:…:line}}.
 */
final class ClosureRichDisplayName
{
    /**
     * Build Zend {@code {closure:…:line}} from enclosing callable + definition site.
     *
     * @param ?string $parentRichName Parent closure's already-built rich name (nested closures)
     */
    public static function build(
        ?string $enclosingFunctionName,
        ?string $enclosingClassName,
        bool $enclosingIsClosure,
        ?string $parentRichName,
        string $file,
        int $line
    ): string {
        $line = max(0, $line);
        $class = '';
        $separator = '';
        $function = $file;
        $parens = '';

        if (null !== $parentRichName && '' !== $parentRichName && $enclosingIsClosure) {
            // Nested in a closure: reuse parent's full name without class/parens (zend_compile.c).
            $function = $parentRichName;
        } elseif (null !== $enclosingFunctionName && '' !== $enclosingFunctionName
            && '{main}' !== $enclosingFunctionName
        ) {
            if ($enclosingIsClosure) {
                $function = $enclosingFunctionName;
            } else {
                $function = $enclosingFunctionName;
                $parens = '()';
                if (null !== $enclosingClassName && '' !== $enclosingClassName) {
                    $class = ltrim($enclosingClassName, '\\');
                    $separator = '::';
                }
            }
        }

        return '{closure:'.$class.$separator.$function.$parens.':'.$line.'}';
    }

    public static function fromEnclosingBlock(
        Block $enclosing,
        int $line,
        ?string $parentRichName = null,
        ?string $fallbackFile = null,
        ?string $compilingClassDisplayName = null
    ): ?string {
        if (!CompilerVersion::supportsClosureRichDebugInfo()) {
            return null;
        }
        $file = $enclosing->scriptPath();
        if ('' === $file && null !== $fallbackFile && '' !== $fallbackFile) {
            $file = $fallbackFile;
        }
        if ('' === $file) {
            $file = 'Standard input code';
        }
        if ($line <= 0) {
            $line = 1;
        }

        $func = $enclosing->func;
        $enclosingName = null;
        $enclosingClass = null;
        $enclosingIsClosure = false;
        if (null !== $func && \is_string($func->name) && '' !== $func->name) {
            $enclosingName = $func->name;
            $enclosingIsClosure = self::isClosureCfgName($enclosingName)
                || (($func->flags ?? 0) & CfgFunc::FLAG_CLOSURE) !== 0;
            if (null !== $func->class) {
                $classVal = $func->class->value ?? null;
                if (\is_string($classVal) && '' !== $classVal) {
                    $enclosingClass = $classVal;
                }
            }
        }
        if ((null === $enclosingClass || '' === $enclosingClass)
            && null !== $compilingClassDisplayName
            && '' !== $compilingClassDisplayName
            && !$enclosingIsClosure
            && null !== $enclosingName
            && '{main}' !== $enclosingName
        ) {
            $enclosingClass = $compilingClassDisplayName;
        }

        // Prefer parent rich name when enclosing block already has one (same Func).
        if (null === $parentRichName || '' === $parentRichName) {
            if (null !== $enclosing->closureRichDisplayName && '' !== $enclosing->closureRichDisplayName) {
                $parentRichName = $enclosing->closureRichDisplayName;
            }
        }

        return self::build(
            $enclosingName,
            $enclosingClass,
            $enclosingIsClosure,
            $parentRichName,
            $file,
            $line
        );
    }

    public static function isClosureCfgName(string $name): bool
    {
        return str_starts_with($name, '{anonymous}')
            || str_starts_with($name, '{closure');
    }

    /** Prefer Block / OpCode rich name when formatting TypeError callables. */
    public static function preferFromBlock(?Block $block, ?string $fallback = null): ?string
    {
        if (null !== $block && null !== $block->closureRichDisplayName && '' !== $block->closureRichDisplayName) {
            return $block->closureRichDisplayName;
        }

        return $fallback;
    }

    public static function preferFromOp(?OpCode $op, ?Block $body = null): ?string
    {
        if (null !== $op && null !== $op->closureRichDisplayName && '' !== $op->closureRichDisplayName) {
            return $op->closureRichDisplayName;
        }
        if (null !== $body && null !== $body->closureRichDisplayName && '' !== $body->closureRichDisplayName) {
            return $body->closureRichDisplayName;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;
use PHPCompiler\VM\Context as VMContext;

/**
 * PHP 8.5+ compile-time E_DEPRECATED for `case <expr>;` / `default;` in switch (#26279).
 *
 * php-src: Zend/zend_language_parser.y (ZEND_ALT_CASE_SYNTAX); Zend/zend_compile.c
 * zend_compile_switch — "Case statements followed by a semicolon (;) are deprecated…".
 * Enum `case Name;` is unaffected (same RFC).
 */
final class SwitchCaseSemicolonDeprecation
{
    public const MESSAGE = 'Case statements followed by a semicolon (;) are deprecated, use a colon (:) instead';

    public static function emitForSource(string $code, string $filename, VMContext $context): void
    {
        if (!CompilerVersion::supportsSwitchCaseSemicolonDeprecation()) {
            return;
        }
        if (!str_contains($code, 'case') && !str_contains($code, 'default')) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return;
        }
        if (!\function_exists('token_get_all')) {
            return;
        }

        $frame = self::resolveEmitFrame($context, $filename);
        $tokens = token_get_all($code);
        $n = \count($tokens);
        /** @var list<array{kind: 'enum'|'switch'|'switch_alt', depth: int}> $stack */
        $stack = [];
        $pendingEnum = false;
        $pendingSwitch = false;
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            $text = \is_array($tok) ? $tok[1] : $tok;
            $id = \is_array($tok) ? $tok[0] : null;

            if (null !== $id && self::isIgnorableToken($id)) {
                ++$i;
                continue;
            }

            if (!$pendingEnum && !$pendingSwitch && null !== $id && \defined('T_ENUM') && T_ENUM === $id) {
                $pendingEnum = true;
                ++$i;
                continue;
            }

            if (!$pendingEnum && !$pendingSwitch && null !== $id && T_SWITCH === $id) {
                $pendingSwitch = true;
                ++$i;
                continue;
            }

            if ($pendingEnum) {
                if ('{' === $text) {
                    $stack[] = ['kind' => 'enum', 'depth' => 1];
                    $pendingEnum = false;
                }
                ++$i;
                continue;
            }

            if ($pendingSwitch) {
                if ('{' === $text) {
                    $stack[] = ['kind' => 'switch', 'depth' => 1];
                    $pendingSwitch = false;
                    ++$i;
                    continue;
                }
                if (':' === $text) {
                    $stack[] = ['kind' => 'switch_alt', 'depth' => 0];
                    $pendingSwitch = false;
                    ++$i;
                    continue;
                }
                ++$i;
                continue;
            }

            if (null !== $id && T_ENDSWITCH === $id) {
                self::popKind($stack, 'switch_alt');
                ++$i;
                continue;
            }

            if ('{' === $text) {
                self::bumpTopDepth($stack, 1);
                ++$i;
                continue;
            }

            if ('}' === $text) {
                self::closeBrace($stack);
                ++$i;
                continue;
            }

            if (null !== $id && (T_CASE === $id || T_DEFAULT === $id)) {
                if (self::isSwitchCaseContext($stack)) {
                    $sep = self::findCaseSeparator($tokens, $i + 1, T_DEFAULT === $id);
                    if (null !== $sep && ';' === $sep['text']) {
                        $context->errors->internalDeprecated(
                            self::MESSAGE,
                            $context,
                            $frame,
                            $filename,
                            $sep['line']
                        );
                    }
                }
                ++$i;
                continue;
            }

            ++$i;
        }
    }

    /**
     * @param list<array{kind: 'enum'|'switch'|'switch_alt', depth: int}> $stack
     */
    private static function isSwitchCaseContext(array $stack): bool
    {
        for ($i = \count($stack) - 1; $i >= 0; --$i) {
            $c = $stack[$i];
            if ('switch' === $c['kind'] || 'switch_alt' === $c['kind']) {
                return true;
            }
            if ('enum' === $c['kind'] && 1 === $c['depth']) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param list<array{kind: 'enum'|'switch'|'switch_alt', depth: int}> $stack
     */
    private static function bumpTopDepth(array &$stack, int $delta): void
    {
        if ([] === $stack) {
            return;
        }
        $top = \count($stack) - 1;
        if ('switch_alt' === $stack[$top]['kind']) {
            return;
        }
        $stack[$top]['depth'] += $delta;
    }

    /**
     * @param list<array{kind: 'enum'|'switch'|'switch_alt', depth: int}> $stack
     */
    private static function closeBrace(array &$stack): void
    {
        if ([] === $stack) {
            return;
        }
        $top = \count($stack) - 1;
        if ('switch_alt' === $stack[$top]['kind']) {
            return;
        }
        --$stack[$top]['depth'];
        if ($stack[$top]['depth'] <= 0) {
            array_pop($stack);
        }
    }

    /**
     * @param list<array{kind: 'enum'|'switch'|'switch_alt', depth: int}> $stack
     */
    private static function popKind(array &$stack, string $kind): void
    {
        for ($i = \count($stack) - 1; $i >= 0; --$i) {
            if ($stack[$i]['kind'] === $kind) {
                array_splice($stack, $i, 1);

                return;
            }
        }
    }

    /**
     * @param list<mixed> $tokens
     *
     * @return null|array{text: string, line: int}
     */
    private static function findCaseSeparator(array $tokens, int $start, bool $isDefault): ?array
    {
        $n = \count($tokens);
        if ($isDefault) {
            for ($i = $start; $i < $n; ++$i) {
                $tok = $tokens[$i];
                if (\is_array($tok) && self::isIgnorableToken($tok[0])) {
                    continue;
                }
                $text = \is_array($tok) ? $tok[1] : $tok;
                if (':' === $text || ';' === $text) {
                    return [
                        'text' => $text,
                        'line' => self::lineForIndex($tokens, $i),
                    ];
                }

                return null;
            }

            return null;
        }

        $depth = 0;
        for ($i = $start; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_array($tok)) {
                if (self::isIgnorableToken($tok[0])) {
                    continue;
                }
                $text = $tok[1];
            } else {
                $text = $tok;
            }

            if (0 === $depth && (':' === $text || ';' === $text)) {
                return [
                    'text' => $text,
                    'line' => self::lineForIndex($tokens, $i),
                ];
            }

            if ('(' === $text || '[' === $text || '{' === $text) {
                ++$depth;
            } elseif (')' === $text || ']' === $text || '}' === $text) {
                --$depth;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function lineForIndex(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            if (\is_array($tokens[$i]) && isset($tokens[$i][2])) {
                return (int) $tokens[$i][2];
            }
        }

        return 0;
    }

    private static function isIgnorableToken(int $id): bool
    {
        return \in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private static function resolveEmitFrame(VMContext $context, string $filename): Frame
    {
        $vm = VM::running();
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null !== $frame) {
                return $frame;
            }
            $frames = $context->runStackFrames();
            if ([] !== $frames) {
                return $frames[0];
            }
        }

        $block = new Block(null);
        $frame = new Frame(null, $block, null);
        $frame->vmContext = $context;
        $frame->scriptPath = $filename;

        return $frame;
    }
}

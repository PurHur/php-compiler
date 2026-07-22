<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;

/**
 * Isolate CFG block maps and operand variable bindings during nested php-in-PHP JIT helper compiles (#8559, #9091, #10343, #17737).
 *
 * Also clears outer emit-helper / self-host stub env so NestedJIT lowers real helper bodies
 * (e.g. VmUrlRewriterOb during RewriteVarsRuntime — #21965, peer SELFHOST_AOT clear).
 *
 * Caller insert restore uses {@see BasicBlockHelper::restoreInsertBlock} so sealed outer blocks
 * get a fresh continue instead of a detached builder (#21972 / peer #21965).
 */
final class NestedJitCompileScope
{
    private static int $depth = 0;

    /** @var list<string> */
    private const CLEAR_STUB_ENV_KEYS = [
        'PHP_COMPILER_SELFHOST_AOT',
        'PHP_COMPILER_EMIT_HELPER_LINK',
    ];

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Re-attach named scope slots after nested helper compiles (#17954).
     */
    public static function resyncNamedBindings(Context $context): void
    {
        foreach ($context->namedVariableBindings as $name => $var) {
            $context->bindVariableByName($name, $var);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $compile
     *
     * @return T
     */
    public static function run(Context $context, callable $compile)
    {
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $savedBlockStorage = $context->scope->blockStorage;
        $savedBlockEntryStorage = $context->scope->blockEntryStorage;
        $savedVariables = $context->scope->variables;
        $savedNamedBindings = $context->namedVariableBindings;
        $context->scope->blockStorage = new \SplObjectStorage();
        $context->scope->blockEntryStorage = new \SplObjectStorage();
        $context->scope->variables = new \SplObjectStorage();
        $context->namedVariableBindings = [];
        $prevStubEnv = self::clearStubEnvForNestedHelperCompile();
        try {
            $context->builder->clearInsertionPosition();
            ++self::$depth;

            return $compile();
        } finally {
            --self::$depth;
            $context->scope->blockStorage = $savedBlockStorage;
            $context->scope->blockEntryStorage = $savedBlockEntryStorage;
            $context->scope->variables = $savedVariables;
            $context->namedVariableBindings = $savedNamedBindings;
            self::resyncNamedBindings($context);
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
            self::restoreStubEnv($prevStubEnv);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private static function clearStubEnvForNestedHelperCompile(): array
    {
        $prev = [];
        if (!\function_exists('putenv')) {
            return $prev;
        }
        foreach (self::CLEAR_STUB_ENV_KEYS as $key) {
            $prev[$key] = \getenv($key);
            \putenv($key.'=0');
        }

        return $prev;
    }

    /**
     * @param array<string, string|false> $prev
     */
    private static function restoreStubEnv(array $prev): void
    {
        if (!\function_exists('putenv')) {
            return;
        }
        foreach ($prev as $key => $value) {
            if (false === $value || null === $value) {
                \putenv($key.'=');
            } else {
                \putenv($key.'='.$value);
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        return BasicBlockHelper::tryGetInsertBlock($context);
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        // Peer GethostbynamelRuntime / #21965 — sealed caller blocks need a fresh continue (#21972).
        if (null !== $block) {
            BasicBlockHelper::restoreInsertBlock($context, $block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** Shared nested-JIT link for base64_encode/base64_decode PHP helpers (#17249). */
final class Base64JitLink
{
    private const HELPER_PATH = '/ext/standard/Base64JitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\Base64JitHelper::encodeArgv';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\Base64JitHelper::decodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
        self::DECODE_HELPER,
    ];

    public static function encodeHelper(): string
    {
        return self::ENCODE_HELPER;
    }

    public static function decodeHelper(): string
    {
        return self::DECODE_HELPER;
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Base64JitHelper compile (#17249)');
        }

        return $fn;
    }

    public static function ensureJitHelpersCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Base64JitHelper.php');
            if (null === $block) {
                throw new \LogicException('Base64JitHelper.php parseAndCompile failed (#17249)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#17249)');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_mime_content_type via MimeContentTypeJitHelper PHP (#9236).
 *
 * Replaces ~150-line LLVM magic-byte sniff + libc strncmp. SSOT: {@see \PHPCompiler\ext\standard\VmMime}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 */
final class MimeContentTypeRuntime
{
    private const HELPER_PATH = '/ext/standard/MimeContentTypeJitHelper.php';

    private const MIME_HELPER = 'PHPCompiler\\ext\\standard\\MimeContentTypeJitHelper::mimeContentType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MIME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_mime_content_type');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_mime_content_type', $probe);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction('__compiler_mime_content_type');

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('mime_content_type_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_mime_content_type', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::MIME_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::MIME_HELPER.' missing after MimeContentTypeJitHelper compile (#9236)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MimeContentTypeJitHelper.php');
        if (null === $block) {
            throw new \LogicException('MimeContentTypeJitHelper.php parseAndCompile failed (#9236)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9236)');
            }
        }
    }
}

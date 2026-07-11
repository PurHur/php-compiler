<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gz* via ZlibJitHelper PHP (#9879, #13347).
 *
 * Replaces deleted ~1027 LOC libz LLVM monolith (#13347). SSOT: {@see \PHPCompiler\ext\standard\VmZlibCore}.
 * php-src: ext/zlib/zlib.c
 */
final class ZlibRuntime
{
    private const HELPER_PATH = '/ext/standard/ZlibJitHelper.php';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::compressArgv';

    private const UNCOMPRESS_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::uncompressArgv';

    private const DEFLATE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::deflateArgv';

    private const INFLATE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::inflateArgv';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::encodeArgv';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::decodeArgv';

    private const ZLIB_ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::zlibEncodeArgv';

    private const ZLIB_DECODE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::zlibDecodeArgv';

    private const ZLIB_GET_CODING_TYPE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::getCodingTypeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPRESS_HELPER,
        self::UNCOMPRESS_HELPER,
        self::DEFLATE_HELPER,
        self::INFLATE_HELPER,
        self::ENCODE_HELPER,
        self::DECODE_HELPER,
        self::ZLIB_ENCODE_HELPER,
        self::ZLIB_DECODE_HELPER,
        self::ZLIB_GET_CODING_TYPE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gzcompress',
        '__compiler_gzuncompress',
        '__compiler_gzdeflate',
        '__compiler_gzinflate',
        '__compiler_gzencode',
        '__compiler_gzdecode',
        '__compiler_zlib_encode',
        '__compiler_zlib_decode',
        '__compiler_zlib_get_coding_type',
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
        $probe = $context->module->getNamedFunction('__compiler_gzcompress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_gzcompress', self::COMPRESS_HELPER, 'compress');
        self::implementIfMissing($context, '__compiler_gzuncompress', self::UNCOMPRESS_HELPER, 'decompress');
        self::implementIfMissing($context, '__compiler_gzdeflate', self::DEFLATE_HELPER, 'compress');
        self::implementIfMissing($context, '__compiler_gzinflate', self::INFLATE_HELPER, 'decompress');
        self::implementIfMissing($context, '__compiler_gzencode', self::ENCODE_HELPER, 'compress');
        self::implementIfMissing($context, '__compiler_gzdecode', self::DECODE_HELPER, 'decompress');
        self::implementIfMissing($context, '__compiler_zlib_encode', self::ZLIB_ENCODE_HELPER, 'zlib_encode');
        self::implementIfMissing($context, '__compiler_zlib_decode', self::ZLIB_DECODE_HELPER, 'decompress');
        self::implementIfMissing($context, '__compiler_zlib_get_coding_type', self::ZLIB_GET_CODING_TYPE_HELPER, 'get_coding_type');
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementIfMissing(
        Context $context,
        string $abiName,
        string $helperLogical,
        string $shape
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        if ('get_coding_type' === $shape) {
            $fn = $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false)
            );
            $entry = $fn->appendBasicBlock('zlib_bridge_entry');
            $failBb = $fn->appendBasicBlock('zlib_bridge_fail');
            $okBb = $fn->appendBasicBlock('zlib_bridge_ok');
            $context->builder->positionAtEnd($entry);

            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, $helperLogical),
                []
            );
            $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
            $context->builder->branchIf($isNullResult, $failBb, $okBb);

            $context->builder->positionAtEnd($okBb);
            $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
            $context->builder->returnValue($result);

            $context->builder->positionAtEnd($failBb);
            $context->builder->returnValue($strPtr->constNull());
            $context->registerFunction($abiName, $fn);
            $context->builder->clearInsertionPosition();

            return;
        }
        $params = match ($shape) {
            'compress' => [$strPtr, $i64, $i64],
            'zlib_encode' => [$strPtr, $i64, $i64],
            'decompress' => [$strPtr, $i64],
            default => throw new \LogicException('unknown zlib ABI shape: '.$shape),
        };
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($strPtr, false, ...$params)
        );

        $entry = $fn->appendBasicBlock('zlib_bridge_entry');
        $failBb = $fn->appendBasicBlock('zlib_bridge_fail');
        $okBb = $fn->appendBasicBlock('zlib_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $args = [$data];
        if ('decompress' === $shape) {
            $args[] = $fn->getParam(1);
        } elseif ('zlib_encode' === $shape) {
            $args[] = $fn->getParam(1);
            $args[] = $fn->getParam(2);
        } else {
            $args[] = $fn->getParam(1);
            $args[] = $fn->getParam(2);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            $args
        );
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failResultBb = $fn->appendBasicBlock('zlib_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('zlib_bridge_result_ok');
        $context->builder->branchIf($isNullResult, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ZlibJitHelper compile (#9879)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ZlibJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ZlibJitHelper.php parseAndCompile failed (#9879)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT zlib (#9879)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ZlibRuntime bridge (#9879)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}

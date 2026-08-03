<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_gz* (#9879, #13347, #23252, #26864).
 *
 * One-shot gz* / zlib_* bodies: thin libz via {@see StringZlibJit} — NestedJIT of
 * {@see \PHPCompiler\ext\standard\ZlibJitHelper} to VmZlibCore SEGV under thin AOT (#26864).
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmZlibCore}.
 *
 * zlib_get_coding_type stays NestedJIT and is linked only when requested (do not pull the
 * helper-runtime ZlibJitHelper unit into every gzcompress binary — #26864 handoff).
 * php-src: ext/zlib/zlib.c
 */
final class ZlibRuntime
{
    private const HELPER_PATH = '/ext/standard/ZlibJitHelper.php';

    private const ZLIB_GET_CODING_TYPE_HELPER = 'PHPCompiler\\ext\\standard\\ZlibJitHelper::getCodingTypeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ZLIB_GET_CODING_TYPE_HELPER,
    ];

    /** @var list<string> */
    private const ONE_SHOT_RUNTIME_FUNCTIONS = [
        '__compiler_gzcompress',
        '__compiler_gzuncompress',
        '__compiler_gzdeflate',
        '__compiler_gzinflate',
        '__compiler_gzencode',
        '__compiler_gzdecode',
        '__compiler_zlib_encode',
        '__compiler_zlib_decode',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    /** Link zlib_get_coding_type only (NestedJIT ObGzhandler probe). */
    public static function ensureGetCodingTypeLinked(Context $context): void
    {
        self::implement($context);
        self::ensureGetCodingType($context);
        $fn = $context->module->getNamedFunction('__compiler_zlib_get_coding_type');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__compiler_zlib_get_coding_type missing after ZlibRuntime (#26864)');
        }
        $context->registerFunction('__compiler_zlib_get_coding_type', $fn);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gzcompress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerOneShotRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        StringZlibJit::implement($context);
        self::registerOneShotRuntime($context);

        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function ensureGetCodingType(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_zlib_get_coding_type');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_zlib_get_coding_type', $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23252'
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->module->addFunction(
            '__compiler_zlib_get_coding_type',
            $context->context->functionType($strPtr, false)
        );
        $entry = $fn->appendBasicBlock('zlib_bridge_entry');
        $failBb = $fn->appendBasicBlock('zlib_bridge_fail');
        $okBb = $fn->appendBasicBlock('zlib_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $helper = JitVmHelperLink::lookupCompiled(
            $context,
            self::ZLIB_GET_CODING_TYPE_HELPER,
            '#23252'
        );
        $raw = JitNestedHelperCoerce::callHelper($context, $helper, []);
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNullResult, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction('__compiler_zlib_get_coding_type', $fn);
        $context->builder->clearInsertionPosition();

        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function registerOneShotRuntime(Context $context): void
    {
        foreach (self::ONE_SHOT_RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ZlibRuntime bridge (#26864)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}

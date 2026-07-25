<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_pack via PackJitHelper PHP (#9133, #13062, #22842).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle} (Ieee754 →
 * PackEngineEncode → PackJitEngine → PackJitHelper) in one NestedJIT scope (#22981).
 *
 * Intentionally not a helper-runtime corpus unit: the constant is
 * {@see self::PACK_HELPER_FILE} (name avoids the emit `*HELPER_PATH` suffix), so
 * emit discovery skips it.
 * Solo unit emit NestedJITs Ieee754 float math that fails module verify under
 * ENV_EMITTING; runtime StringPack NestedJIT in a full AOT context is the path.
 * php-src: ext/standard/pack.c
 */
final class StringPack
{
    private const IEEE_PATH = '/ext/standard/Ieee754.php';

    private const ENCODE_PATH = '/ext/standard/PackEngineEncode.php';

    private const ENGINE_PATH = '/ext/standard/PackJitEngine.php';

    /** Repo-root path for PackJitHelper — not `*HELPER_PATH` (corpus skip — #22981). */
    private const PACK_HELPER_FILE = '/ext/standard/PackJitHelper.php';

    /**
     * Ordered NestedJIT sources for runtime StringPack (#22981).
     *
     * @var list<string>
     */
    private const PACK_HELPER_BUNDLE = [
        self::IEEE_PATH,
        self::ENCODE_PATH,
        self::ENGINE_PATH,
        self::PACK_HELPER_FILE,
    ];

    private const PACK_HELPER = 'PHPCompiler\\ext\\standard\\PackJitHelper::packArgv';

    /** @var list<string> */
    private const COMPILED_PACK_HELPERS = [
        self::PACK_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_pack',
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
        $probe = $context->module->getNamedFunction('__compiler_pack');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        PackArgvSerialize::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementPackBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPackBridge(Context $context): void
    {
        $abiName = '__compiler_pack';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('pack_bridge_entry');
        $fmtNull = $fn->appendBasicBlock('pack_bridge_fmt_null');
        $packBody = $fn->appendBasicBlock('pack_bridge_body');

        $context->builder->positionAtEnd($entry);
        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);
        $nullFmt = $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull());
        $context->builder->branchIf($nullFmt, $fmtNull, $packBody);

        $context->builder->positionAtEnd($fmtNull);
        TypeErrorRaise::ensureLinked($context);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('pack(): Argument #1 ($format) must be of type string'),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $msg,
            $context->builder->intCast($msgLen, $sizeT)
        );
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($empty);

        // Always run PackJitHelper — empty format must still warn on unused args (#22687).
        $context->builder->positionAtEnd($packBody);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $blob = $context->builder->call(
            $context->lookupFunction('phpc_pack_argv_serialize'),
            $argc,
            $argv
        );
        $packed = $context->builder->call(
            self::helperFunction($context, self::PACK_HELPER),
            $fmtSep,
            $blob
        );
        $context->builder->returnValue($packed);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        TypeErrorRaise::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['strlen', $i64, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22842');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::PACK_HELPER_BUNDLE,
            self::COMPILED_PACK_HELPERS,
            '#22842'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringPack bridge (#9133)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gettext* via GettextJitHelper PHP (#9859, #12828).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\gettext\GettextJitHelper}; thin LLVM bridges
 * forward the ABI. SSOT: {@see \PHPCompiler\ext\gettext\VmGettextNative}.
 * php-src: ext/gettext/gettext.c
 */
final class StringGettextRuntime
{
    private const HELPER_PATH = '/ext/gettext/GettextJitHelper.php';

    private const GETTEXT_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::gettextArgv';

    private const DGETTEXT_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::dgettextArgv';

    private const DCGETTEXT_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::dcgettextArgv';

    private const DNGETTEXT_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::dngettextArgv';

    private const DCNGETTEXT_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::dcngettextArgv';

    private const BIND_QUERY_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::bindtextdomainQuery';

    private const BIND_SET_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::bindtextdomainSet';

    private const TEXTDOMAIN_QUERY_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::textdomainQuery';

    private const TEXTDOMAIN_SET_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::textdomainSet';

    private const CODESET_QUERY_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::bindTextdomainCodesetQuery';

    private const CODESET_SET_HELPER = 'PHPCompiler\\ext\\gettext\\GettextJitHelper::bindTextdomainCodesetSet';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETTEXT_HELPER,
        self::DGETTEXT_HELPER,
        self::DCGETTEXT_HELPER,
        self::DNGETTEXT_HELPER,
        self::DCNGETTEXT_HELPER,
        self::BIND_QUERY_HELPER,
        self::BIND_SET_HELPER,
        self::TEXTDOMAIN_QUERY_HELPER,
        self::TEXTDOMAIN_SET_HELPER,
        self::CODESET_QUERY_HELPER,
        self::CODESET_SET_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gettext',
        '__compiler_dgettext',
        '__compiler_dcgettext',
        '__compiler_dngettext',
        '__compiler_dcngettext',
        '__compiler_bindtextdomain',
        '__compiler_textdomain',
        '__compiler_bind_textdomain_codeset',
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
        $probe = $context->module->getNamedFunction('__compiler_gettext');
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
        self::implementStringReturnBridge($context, '__compiler_gettext', self::GETTEXT_HELPER, 1);
        self::implementStringReturnBridge($context, '__compiler_dgettext', self::DGETTEXT_HELPER, 2);
        self::implementDcgettextBridge($context);
        self::implementDngettextBridge($context);
        self::implementDcngettextBridge($context);
        self::implementOptionalStringOutBridge(
            $context,
            '__compiler_bindtextdomain',
            self::BIND_QUERY_HELPER,
            self::BIND_SET_HELPER,
            1
        );
        self::implementTextdomainBridge($context);
        self::implementOptionalStringOutBridge(
            $context,
            '__compiler_bind_textdomain_codeset',
            self::CODESET_QUERY_HELPER,
            self::CODESET_SET_HELPER,
            1
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStringReturnBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $paramTypes = array_fill(0, $paramCount, $strPtr);
        if ('__compiler_dcgettext' === $abiName || '__compiler_dcngettext' === $abiName) {
            // declared in dedicated bridge methods
            return;
        }
        $ft = $context->context->functionType($strPtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gettext_str_entry');
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            $args
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDcgettextBridge(Context $context): void
    {
        $abiName = '__compiler_dcgettext';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dcgettext_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $category = $context->builder->trunc($fn->getParam(2), $i32);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DCGETTEXT_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $category]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDngettextBridge(Context $context): void
    {
        $abiName = '__compiler_dngettext';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dngettext_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $count = $context->builder->trunc($fn->getParam(3), $i32);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DNGETTEXT_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $count]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDcngettextBridge(Context $context): void
    {
        $abiName = '__compiler_dcngettext';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dcngettext_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $count = $context->builder->trunc($fn->getParam(3), $i32);
        $category = $context->builder->trunc($fn->getParam(4), $i32);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DCNGETTEXT_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $count, $category]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTextdomainBridge(Context $context): void
    {
        $abiName = '__compiler_textdomain';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('textdomain_entry');
        $queryBb = $fn->appendBasicBlock('textdomain_query');
        $setBb = $fn->appendBasicBlock('textdomain_set');
        $context->builder->positionAtEnd($entry);

        $domain = $fn->getParam(0);
        $out = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $domain, $strPtr->constNull());
        $context->builder->branchIf($isNull, $queryBb, $setBb);

        $context->builder->positionAtEnd($queryBb);
        $queryRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TEXTDOMAIN_QUERY_HELPER),
            []
        );
        self::writeHelperStringOrFalseToValue($context, $out, $queryRaw);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($setBb);
        $setRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TEXTDOMAIN_SET_HELPER),
            [$domain]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $setRaw);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementOptionalStringOutBridge(
        Context $context,
        string $abiName,
        string $queryHelper,
        string $setHelper,
        int $optionalParamIndex
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gettext_opt_entry');
        $queryBb = $fn->appendBasicBlock('gettext_opt_query');
        $setBb = $fn->appendBasicBlock('gettext_opt_set');
        $context->builder->positionAtEnd($entry);

        $fixedArgs = [];
        for ($i = 0; $i < $optionalParamIndex; ++$i) {
            $fixedArgs[] = $fn->getParam($i);
        }
        $optional = $fn->getParam($optionalParamIndex);
        $out = $fn->getParam($optionalParamIndex + 1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $optional, $strPtr->constNull());
        $context->builder->branchIf($isNull, $queryBb, $setBb);

        $context->builder->positionAtEnd($queryBb);
        $queryRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $queryHelper),
            $fixedArgs
        );
        self::writeHelperStringOrFalseToValue($context, $out, $queryRaw);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($setBb);
        $setArgs = $fixedArgs;
        $setArgs[] = $optional;
        $setRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $setHelper),
            $setArgs
        );
        self::writeHelperStringOrFalseToValue($context, $out, $setRaw);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function writeHelperStringOrFalseToValue(Context $context, Value $out, Value $raw): void
    {
        $i32 = $context->getTypeFromString('int32');
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $falseBb = BasicBlockHelper::append($context, 'gettext_result_false');
        $okBb = BasicBlockHelper::append($context, 'gettext_result_string');
        $doneBb = BasicBlockHelper::append($context, 'gettext_result_done');

        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GettextJitHelper compile (#9859)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GettextJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GettextJitHelper.php parseAndCompile failed (#9859)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT gettext (#9859)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringGettextRuntime bridge (#9859)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * C-floor Runtime::parse for M5 argv / gen-0 seed (#26756 / re-#23468).
 *
 * Why this exists:
 * - Host-lowering Runtime.php::parse reintroduces mid-BB terminators after master drift
 *   and the prepare-identity list-unpack SEGVs at runtime (c:main_before_php).
 * - C-floor initParsePipeline only allocates a shallow Parser shell; PHPCfg\Parser::parse
 *   is not in the argv driver today (nm shows no Parser symbols).
 *
 * Prefer real Parser::parse when NestedJIT-registered ({@see RuntimeParseM5PhpCfgParser}).
 * Call ABI: parse(this __object__*, code __string__*, file __string__*) -> Script __object__*
 * (forced under M5 NestedJIT — #27426). Functional-smoke `echo "TOKEN"` is handled by
 * {@see M5TrivialEchoScript::parseAndCompile} when NestedJIT-registered (opt-in
 * PHP_COMPILER_M5_TRIVIAL_ECHO_NESTEDJIT) via
 * {@see BootstrapCompileSmokeM3Emit::emitRuntimeParseAndCompileDefault}.
 */
final class RuntimeParseM5Native
{
    /**
     * @param callable(string):string $llvmInternalName
     */
    public static function emitFunction(
        Context $context,
        string $internalName,
        string $logicalName,
        callable $llvmInternalName
    ): Value {
        $lc = strtolower($logicalName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        $objectPtr = $context->getTypeFromString('__object__*');
        $stringPtr = $context->getTypeFromString('__string__*');
        $func = $context->module->addFunction(
            $llvmInternalName($internalName),
            $context->context->functionType($objectPtr, false, $objectPtr, $stringPtr, $stringPtr)
        );
        $bb = $func->appendBasicBlock('m5_parse_null');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);

        // Prefer real Parser::parse when NestedJIT/force-include has registered it.
        $parserFn = self::lookupParserParse($context);
        if (null !== $parserFn) {
            $runtime = $func->getParam(0);
            $code = $func->getParam(1);
            $filename = $func->getParam(2);
            $object = $context->type->object;
            $parserVar = $object->propertyFetch($runtime, 'PHPCompiler\\Runtime', 'parser');
            // objectPropertySlot holds a Variable; use its LLVM value as the receiver.
            $parserObj = $parserVar->value;
            $script = $context->builder->call($parserFn, $parserObj, $code, $filename);
            $context->builder->returnValue($script);
        } else {
            $context->builder->returnValue($objectPtr->constNull());
        }

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__object__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $stringPtr, $stringPtr],
            []
        );

        return $func;
    }

    private static function lookupParserParse(Context $context): ?Value
    {
        foreach (['phpcfg\\parser::parse', 'php\\cfg\\parser::parse'] as $lc) {
            if (isset($context->functions[$lc])) {
                return $context->functions[$lc];
            }
        }
        foreach (['PHPCfg_Parser__parse', 'phpcfg_parser__parse'] as $mangled) {
            $existing = $context->module->getNamedFunction($mangled);
            if (null !== $existing) {
                return $existing;
            }
        }

        return null;
    }
}

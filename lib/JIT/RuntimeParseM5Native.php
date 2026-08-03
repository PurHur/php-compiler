<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * C-floor Runtime::parse for M5 argv / gen-0 seed (#26756 / re-#23468 / #27426).
 *
 * Why this exists:
 * - Host-lowering Runtime.php::parse reintroduces mid-BB terminators after master drift
 *   and the prepare-identity list-unpack SEGVs at runtime (c:main_before_php).
 * - C-floor initParsePipeline only allocates a shallow Parser shell; PHPCfg\Parser::parse
 *   is not in the argv driver today (nm shows no Parser symbols) unless FORCE_PARSER.
 *
 * FORCE_PARSER NestedJIT of PHPCfg\Parser::parse still SIGABRTs at runtime when invoked
 * (#27426): NestedJIT'd {@see M5ParserAstPeer::parse} constructs PhpParser\Node objects
 * that are not NestedJIT-safe, and parseAst(null) aborts on peer miss. Symbols are still
 * NestedJIT'd for nm proof under PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT=1, but this C-floor
 * does **not** call them unless PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT_CALL=1 (experimental).
 *
 * Default: return null Script — {@see M5TrivialEchoNative} handles limited shapes in
 * parseAndCompile before this fallback. Functional-smoke stays on the trivial-echo path.
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
        $bb = $func->appendBasicBlock('m5_parse');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);

        $parserFn = self::lookupParserParse($context);
        $callNested = self::shouldCallNestedJitParser();
        if (null !== $parserFn && $callNested) {
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
            // Default / FORCE_PARSER-without-CALL: null Script (avoid SIGABRT — #27426).
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

    /**
     * Opt-in runtime call into NestedJIT'd PHPCfg\Parser::parse (still SIGABRTs — #27426).
     * Default off so TrivialEcho-miss returns null instead of aborting the argv driver.
     */
    private static function shouldCallNestedJitParser(): bool
    {
        $flag = getenv('PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT_CALL');

        return '1' === $flag || 'true' === strtolower((string) $flag);
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

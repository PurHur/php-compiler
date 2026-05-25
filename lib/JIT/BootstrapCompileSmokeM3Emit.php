<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin native LLVM bridge for BootstrapAot\compile_smoke_m3_emit (#1983, approach 3).
 *
 * Calls already-linked Runtime spine symbols instead of full PHP CFG lowering.
 */
final class BootstrapCompileSmokeM3Emit
{
    private const MODE_AOT = 2;

    private static int $seq = 0;

    public static function emit(Context $context, Value $sourceFile, Value $outFile): void
    {
        $tag = 'csm3'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $objPtr = $context->getTypeFromString('__object__*');
        $strMap = $context->structFieldMap['__string__'];
        $retOk = $i64->constInt(0, false);
        $retFail = $i64->constInt(1, false);

        $code = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $sourceFile
        );
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $strPtr->constNull());
        $readFail = BasicBlockHelper::append($context, 'csm3_read_fail_'.$tag);
        $readOk = BasicBlockHelper::append($context, 'csm3_read_ok_'.$tag);
        $context->builder->branchIf($codeNull, $readFail, $readOk);

        $context->builder->positionAtEnd($readFail);
        self::echoPhaseError($context, 'compile_smoke_m3_emit: empty source (native bridge)', 'source');
        $context->builder->returnValue($retFail);

        $context->builder->positionAtEnd($readOk);
        $codeLen = $context->builder->call($context->lookupFunction('__string__strlen'), $code);
        $codeEmpty = $context->builder->icmp(Builder::INT_EQ, $codeLen, $i64->constInt(0, false));
        $emptyFail = BasicBlockHelper::append($context, 'csm3_empty_fail_'.$tag);
        $emptyOk = BasicBlockHelper::append($context, 'csm3_empty_ok_'.$tag);
        $context->builder->branchIf($codeEmpty, $emptyFail, $emptyOk);

        $context->builder->positionAtEnd($emptyFail);
        self::echoPhaseError($context, 'compile_smoke_m3_emit: empty source (native bridge)', 'source');
        $context->builder->returnValue($retFail);

        $context->builder->positionAtEnd($emptyOk);
        $object = $context->type->object;
        $runtimeId = $object->lookup('PHPCompiler\\Runtime');
        $runtime = $object->allocate($runtimeId);
        $context->builder->call(
            self::runtimeSpine($context, '__construct', 'void', ['__object__*', 'int64']),
            $runtime,
            $i64->constInt(self::MODE_AOT, false)
        );

        $block = $context->builder->call(
            self::runtimeSpine($context, 'parseandcompile', '__object__*', ['__object__*', '__string__*', '__string__*']),
            $runtime,
            $code,
            $sourceFile
        );
        $blockNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
        $pacFail = BasicBlockHelper::append($context, 'csm3_pac_fail_'.$tag);
        $pacOk = BasicBlockHelper::append($context, 'csm3_pac_ok_'.$tag);
        $context->builder->branchIf($blockNull, $pacFail, $pacOk);

        $context->builder->positionAtEnd($pacFail);
        self::echoPhaseError(
            $context,
            'compile_smoke_m3_emit: parseAndCompile returned null (CFG/compile spine)',
            'parseAndCompile'
        );
        $context->builder->returnValue($retFail);

        $context->builder->positionAtEnd($pacOk);
        $context->builder->call(
            self::runtimeSpine($context, 'standalone', 'void', ['__object__*', '__object__*', '__string__*']),
            $runtime,
            $block,
            $outFile
        );

        ValueEchoHelper::echoLiteral($context, 'compile_smoke_m3_emit: compile OK -> ');
        $outLen = $context->builder->load($context->builder->structGep($outFile, $strMap['length']));
        $outChars = $context->builder->structGep($outFile, $strMap['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $outChars,
            $context->builder->zExt($outLen, $sizeT)
        );
        ValueEchoHelper::echoLiteral($context, "\n");
        $context->builder->returnValue($retOk);
    }

    private static function echoPhaseError(Context $context, string $line1, string $phase): void
    {
        ValueEchoHelper::echoLiteral($context, $line1."\n");
        ValueEchoHelper::echoLiteral($context, 'compile_smoke_m3_emit: native emit failed at phase='.$phase."\n");
    }

    /**
     * @param list<string> $paramTypeNames
     */
    private static function runtimeSpine(
        Context $context,
        string $methodLc,
        string $returnTypeName,
        array $paramTypeNames
    ): Value {
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        $mangled = preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical;
        $existing = $context->module->getNamedFunction($mangled);
        if (null !== $existing) {
            return $existing;
        }
        $params = [];
        foreach ($paramTypeNames as $typeName) {
            $params[] = $context->getTypeFromString($typeName);
        }

        return $context->module->addFunction(
            $mangled,
            $context->context->functionType(
                $context->getTypeFromString($returnTypeName),
                false,
                ...$params
            )
        );
    }
}

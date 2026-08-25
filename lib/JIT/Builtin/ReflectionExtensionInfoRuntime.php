<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionExtension::info() (#34181).
 *
 * Extension name → php_info_print_module text subset baked at compile time via
 * {@see VmReflection::reflectionExtensionInfoText} (peer VM #22247).
 *
 * Unknown name → no output.
 *
 * php-src: zim_ReflectionExtension_info / php_info_print_module
 */
final class ReflectionExtensionInfoRuntime
{
    private const MAX_NAME_LEN = 512;

    public static function emit(Context $context, Value $nameCstr, Value $nameLen): void
    {
        LibcExtern::ensureMemcmpDecl($context);
        ObOutput::registerExternals($context);

        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_einfo';
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock($tag.'_done');
        $miss = $fn->appendBasicBlock($tag.'_miss');
        $fold = $fn->appendBasicBlock($tag.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType(self::MAX_NAME_LEN));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt(self::MAX_NAME_LEN, false)
        );
        $context->builder->branchIf($tooLong, $miss, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($tag.'_fold_loop');
        $afterFold = $fn->appendBasicBlock($tag.'_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock($tag.'_fold_body');
        $context->builder->branchIf($foldDone, $afterFold, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($nameCstr, $idx);
        $ch = $context->builder->load($srcPtr);
        $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
        $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ch, $i8->constInt(32, true));
        $folded = $context->builder->select($isUpper, $lowered, $ch);
        $dstPtr = $context->builder->gep($bufPtr, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);

        $checkBlock = $afterFold;
        $hitIdx = 0;
        foreach (self::extensionLcToInfoText($context) as $lcExt => $text) {
            $wantLenInt = \strlen($lcExt);
            if (0 === $wantLenInt || '' === $text) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($tag.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($tag.'_try_'.$hitIdx);
            $context->builder->positionAtEnd($checkBlock);

            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcExt);
            $wantStr = $context->builder->load($wantGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantStr, $strMap['value']),
                $i8p
            );
            $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $wantLen);
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $bufPtr,
                $wantCstr,
                $context->builder->zExt($wantLen, $i64)
            );
            $nameEq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $i32->constInt(0, false)
            );
            $match = $context->builder->and($lenEq, $nameEq);
            $context->builder->branchIf($match, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            self::echoBakedText($context, $text);
            $context->builder->branch($done);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function echoBakedText(Context $context, string $text): void
    {
        ObOutputRuntime::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $strMap = $context->structFieldMap['__string__'];
        $infoStr = $context->builder->load($context->constantStringFromString($text));
        $infoCstr = $context->builder->pointerCast(
            $context->builder->structGep($infoStr, $strMap['value']),
            $i8p
        );
        $infoLen = $context->builder->load(
            $context->builder->structGep($infoStr, $strMap['length'])
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $infoCstr,
            $infoLen
        );
    }

    /**
     * @return array<string, string> lowercase extension → info text
     */
    private static function extensionLcToInfoText(Context $context): array
    {
        $pairs = [];
        foreach (ModuleRegistry::getLoadedExtensions() as $loaded) {
            $loaded = (string) $loaded;
            if ('' === $loaded) {
                continue;
            }
            $lc = strtolower($loaded);
            $text = VmReflection::reflectionExtensionInfoText($loaded);
            if ('' === $text) {
                continue;
            }
            $pairs[$lc] = $text;
        }
        ksort($pairs);

        return $pairs;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::__toString() (#34135).
 *
 * Name (from ReflectionClass) → Zend-shaped class dump string baked from
 * {@see Type\Object_} (+ VM ClassEntry when present). Peer name-memcmp tables
 * in {@see ReflectionClassGetFileNameRuntime} / {@see ReflectionClassNameListRuntime}.
 *
 * Must not use {@see Type\Object_::classIdFromRuntimeName} — that aborts on
 * names absent from the JIT class table.
 *
 * php-src: ext/reflection/php_reflection.c — _class_string / zim_ReflectionClass___toString
 * VM SSOT: {@see ReflectionSupport::classReflectionToString}
 */
final class ReflectionClassToStringRuntime
{
    private const MAX_NAME_LEN = 512;

    private const EMPTY_DUMP = <<<'TXT'
Class [ <user> class unknown ] {

  - Constants [0] {
  }

  - Static properties [0] {
  }

  - Static methods [0] {
  }

  - Properties [0] {
  }

  - Methods [0] {
  }
}
TXT;

    /**
     * @return Value __value__* result slot (string)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_tostring';
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($tag.'_merge');
        $miss = $fn->appendBasicBlock($tag.'_miss');
        $fold = $fn->appendBasicBlock($tag.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');

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
        foreach (self::classLcToDump($context) as $lcName => $dump) {
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt || '' === $dump) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($tag.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($tag.'_try_'.$hitIdx);
            $context->builder->positionAtEnd($checkBlock);

            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcName);
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
            $str = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(\strlen($dump), false),
                $context->builder->pointerCast(
                    $context->constantFromString($dump),
                    $charPtr
                )
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $resultPtr,
                $str
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $fallback = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen(self::EMPTY_DUMP), false),
            $context->builder->pointerCast(
                $context->constantFromString(self::EMPTY_DUMP),
                $charPtr
            )
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $resultPtr,
            $fallback
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, string> lowercase class → Zend-shaped dump
     */
    private static function classLcToDump(Context $context): array
    {
        /** @var array<string, string> $pairs */
        $pairs = [];
        $object = $context->type->object;
        $vmCtx = $context->runtime->vmContext ?? null;

        foreach ($object->allClassNamesById() as $id => $className) {
            $id = (int) $id;
            $display = $object->classNameForId($id);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            $lc = strtolower(ltrim($display, '\\'));
            if ('' === $lc) {
                continue;
            }
            $dump = null;
            if (null !== $vmCtx) {
                $dump = self::tryVmDump($vmCtx, $display);
            }
            if (null === $dump) {
                $dump = self::dumpFromObject($object, $id, $display, $vmCtx);
            }
            $pairs[$lc] = $dump;
        }

        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                $lc = strtolower(ltrim($entry->name, '\\'));
                if ('' === $lc || isset($pairs[$lc])) {
                    continue;
                }
                $dump = self::tryVmDump($vmCtx, $entry->name);
                if (null !== $dump) {
                    $pairs[$lc] = $dump;
                }
            }
        }

        ksort($pairs);

        return $pairs;
    }

    private static function tryVmDump(\PHPCompiler\VM\Context $vmCtx, string $className): ?string
    {
        try {
            $rc = ReflectionSupport::newReflectionClassObjectForName($vmCtx, $className);

            return ReflectionSupport::classReflectionToString($vmCtx, $rc);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Structured subset of php-src _class_string from {@see Type\Object_}
     * (AOT may lack VM ClassEntry::sourceLocation — #34096).
     */
    private static function dumpFromObject(
        Type\Object_ $object,
        int $classId,
        string $display,
        ?\PHPCompiler\VM\Context $vmCtx
    ): string {
        $lc = strtolower(ltrim($display, '\\'));
        $isInternal = false;
        $ext = '';
        if (null !== $vmCtx) {
            $entry = $vmCtx->classes[$lc] ?? null;
            if ($entry instanceof ClassEntry) {
                $isInternal = $entry->isInternal;
                if ($isInternal) {
                    $ext = \PHPCompiler\ext\standard\VmReflection::extensionNameForInternalClass($entry->name);
                }
            }
        }

        $tag = $isInternal ? '<internal' : '<user';
        if ($isInternal && '' !== $ext) {
            $tag .= ':'.$ext;
        }
        $tag .= '>';

        $mods = '';
        if ($object->isAbstractClassLc($lc)) {
            $mods .= 'abstract ';
        }
        if ($object->isFinalClassLc($lc)) {
            $mods .= 'final ';
        }
        if ($object->isReadonlyClass($classId)) {
            $mods .= 'readonly ';
        }

        if ($object->isInterfaceClassLc($lc)) {
            $kind = 'interface';
        } elseif ($object->isTraitClass($lc)) {
            $kind = 'trait';
        } elseif ($object->isEnumClassLc($lc)) {
            if (!str_contains($mods, 'final ')) {
                $mods .= 'final ';
            }
            $kind = 'class';
        } else {
            $kind = 'class';
        }

        $implements = '';
        $ifaceNames = [];
        foreach ($object->interfacesForClassImplementsLc($lc) as $ifaceLc) {
            $ifaceLc = strtolower(ltrim((string) $ifaceLc, '\\'));
            if ('' === $ifaceLc) {
                continue;
            }
            $ifaceDisplay = $ifaceLc;
            foreach ($object->allClassNamesById() as $iid => $iname) {
                $iid = (int) $iid;
                $idisp = $object->classNameForId($iid);
                if (!\is_string($idisp) || '' === $idisp) {
                    $idisp = \is_string($iname) ? $iname : '';
                }
                if (strtolower(ltrim($idisp, '\\')) === $ifaceLc) {
                    $ifaceDisplay = $idisp;
                    break;
                }
            }
            $ifaceNames[] = $ifaceDisplay;
        }
        if ([] !== $ifaceNames) {
            $implements = ' implements '.implode(', ', $ifaceNames);
        }

        $out = "Class [ {$tag} {$mods}{$kind} {$display}{$implements} ] {\n";

        $loc = $object->classSourceLocation($classId);
        if (null !== $loc) {
            $loc = $loc->forReflection();
        }
        if (null !== $loc && !$isInternal && '' !== $loc->filename && $loc->startLine > 0) {
            $end = $loc->endLine > 0 ? $loc->endLine : $loc->startLine;
            $out .= "  @@ {$loc->filename} {$loc->startLine} - {$end}\n";
        }

        $constants = [];
        foreach ($object->classConstantsForId($classId) as [$key, $_entry]) {
            $constants[] = $object->classConstDisplayName($classId, (string) $key);
        }

        $staticPropSet = [];
        foreach (array_keys($object->staticPropertyGlobalsForClass($classId)) as $staticName) {
            $staticPropSet[strtolower((string) $staticName)] = (string) $staticName;
        }

        $staticProps = [];
        $instanceProps = [];
        foreach ($object->allPropertiesForReflection($classId) as $spec) {
            $name = $spec['display'];
            if (isset($staticPropSet[strtolower($name)])) {
                $staticProps[] = $name;
            } else {
                $instanceProps[] = $name;
            }
        }

        $staticMethods = [];
        $instanceMethods = [];
        foreach ($object->allMethodsForReflection($classId) as $spec) {
            $name = $spec['display'];
            $methodLc = strtolower($name);
            $vis = $object->methodVisibility($classId, $methodLc);
            if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                $staticMethods[] = $name;
            } else {
                $instanceMethods[] = $name;
            }
        }

        $out .= "\n  - Constants [".\count($constants)."] {\n";
        foreach ($constants as $constName) {
            $out .= "    Constant [ {$constName} ]\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Static properties [".\count($staticProps)."] {\n";
        foreach ($staticProps as $propName) {
            $out .= "    Property [ \${$propName} ]\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Static methods [".\count($staticMethods)."] {\n";
        foreach ($staticMethods as $methodName) {
            $out .= "    Method [ {$methodName} ] {\n    }\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Properties [".\count($instanceProps)."] {\n";
        foreach ($instanceProps as $propName) {
            $out .= "    Property [ \${$propName} ]\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Methods [".\count($instanceMethods)."] {\n";
        foreach ($instanceMethods as $methodName) {
            $out .= "    Method [ {$methodName} ] {\n    }\n";
        }
        $out .= "  }\n";

        $out .= "}\n";

        return $out;
    }
}

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
 * Bake Zend-shaped `_class_string` dumps from {@see Type\Object_} (+ VM
 * ClassEntry for internals) at emit time; memcmp dispatch by class name.
 *
 * php-src: zim_ReflectionClass___toString / _class_string
 */
final class ReflectionClassToStringRuntime
{
    private const MAX_NAME_LEN = 512;

    private static int $emitSeq = 0;

    /**
     * @return Value __value__* result slot (string)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $seq = (string) ++self::$emitSeq;
        $tag = 'refl_cts_'.$seq;
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($tag.'_merge');
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
            $dumpStr = $context->builder->load($context->constantStringFromString($dump));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $resultSlot),
                $dumpStr
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $fallback = $context->builder->load($context->constantStringFromString(
            "Class [ <user> class  ] {\n\n  - Constants [0] {\n  }\n\n  - Static properties [0] {\n  }\n\n  - Static methods [0] {\n  }\n\n  - Properties [0] {\n  }\n\n  - Methods [0] {\n  }\n}\n"
        ));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $fallback
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, string> lowercase class → dump text
     */
    private static function classLcToDump(Context $context): array
    {
        $pairs = [];
        $object = $context->type->object;
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
            // Skip Reflection* / compiler internals that are not user-script targets.
            if (str_starts_with($lc, 'reflection') || str_starts_with($lc, 'phpcompiler\\')) {
                continue;
            }
            $pairs[$lc] = self::dumpFromObject($context, $id, $display, $lc);
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry || !$entry->isInternal) {
                    continue;
                }
                $lc = strtolower(ltrim($entry->name, '\\'));
                if ('' === $lc || isset($pairs[$lc])) {
                    continue;
                }
                if (str_starts_with($lc, 'reflection')) {
                    continue;
                }
                $pairs[$lc] = self::dumpFromVmEntry($vmCtx, $entry);
            }
        }

        ksort($pairs);

        return $pairs;
    }

    private static function dumpFromObject(
        Context $context,
        int $classId,
        string $display,
        string $lc
    ): string {
        $object = $context->type->object;
        $isInternal = !$object->hasUserDeclaredClass($display);
        $tag = $isInternal ? '<internal>' : '<user>';

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

        $extends = '';
        $parent = $object->parentClassDisplayName($display);
        if (null !== $parent && '' !== $parent) {
            $extends = ' extends '.$parent;
        }

        $implements = '';
        $ifaces = [];
        foreach ($object->interfacesForClassImplementsLc($lc) as $ifaceLc) {
            $ifaceLc = strtolower(ltrim((string) $ifaceLc, '\\'));
            if ('' === $ifaceLc) {
                continue;
            }
            $ifaceDisplay = $ifaceLc;
            foreach ($object->allClassNamesById() as $iid => $iname) {
                $idisp = $object->classNameForId((int) $iid);
                if (!\is_string($idisp) || '' === $idisp) {
                    $idisp = \is_string($iname) ? $iname : '';
                }
                if (strtolower(ltrim($idisp, '\\')) === $ifaceLc) {
                    $ifaceDisplay = $idisp;
                    break;
                }
            }
            $ifaces[] = $ifaceDisplay;
        }
        if ([] !== $ifaces) {
            $implements = ' implements '.implode(', ', $ifaces);
        }

        $out = "Class [ {$tag} {$mods}{$kind} {$display}{$extends}{$implements} ] {\n";

        $loc = $object->classSourceLocation($classId);
        if (null !== $loc) {
            $loc = $loc->forReflection();
        }
        if (null !== $loc && !$isInternal && '' !== $loc->filename && $loc->startLine > 0) {
            $end = $loc->endLine > 0 ? $loc->endLine : $loc->startLine;
            // Zend _class_string: "@@ file start-end" (no spaces around dash).
            $out .= "  @@ {$loc->filename} {$loc->startLine}-{$end}\n";
        }

        $constants = [];
        foreach ($object->classConstantsForId($classId) as [$key, $_entry]) {
            if (\is_string($key) && '' !== $key) {
                $constants[] = $object->classConstDisplayName($classId, $key);
            }
        }
        $out .= "\n  - Constants [".\count($constants)."] {\n";
        foreach ($constants as $constName) {
            $out .= "    Constant [ {$constName} ]\n";
        }
        $out .= "  }\n";

        $staticProps = [];
        foreach (array_keys($object->reflectionDefaultStaticPropertyEntries($classId)) as $name) {
            if (\is_string($name) && '' !== $name) {
                $staticProps[] = $name;
            }
        }
        $instanceProps = [];
        foreach ($object->allPropertiesForReflection($classId, 0) as $spec) {
            $name = (string) ($spec['display'] ?? '');
            if ('' === $name || \in_array($name, $staticProps, true)) {
                continue;
            }
            $instanceProps[] = $name;
        }

        $out .= "\n  - Static properties [".\count($staticProps)."] {\n";
        foreach ($staticProps as $propName) {
            $out .= "    Property [ public static \${$propName} ]\n";
        }
        $out .= "  }\n";

        $staticMethods = [];
        $instanceMethods = [];
        foreach ($object->allMethodsForReflection($classId, 0) as $spec) {
            $name = (string) ($spec['display'] ?? '');
            if ('' === $name) {
                continue;
            }
            $declLc = strtolower(ltrim((string) ($spec['declaringClass'] ?? $display), '\\'));
            $declId = $object->lookup($declLc);
            $vis = $object->methodVisibility($declId, strtolower($name));
            if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                $staticMethods[] = $name;
            } else {
                $instanceMethods[] = $name;
            }
        }

        $userTag = $isInternal ? '<internal>' : '<user>';
        $out .= "\n  - Static methods [".\count($staticMethods)."] {\n";
        foreach ($staticMethods as $methodName) {
            $out .= "    Method [ {$userTag} static public method {$methodName} ] {\n    }\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Properties [".\count($instanceProps)."] {\n";
        foreach ($instanceProps as $propName) {
            $out .= "    Property [ public \${$propName} ]\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Methods [".\count($instanceMethods)."] {\n";
        foreach ($instanceMethods as $methodName) {
            $out .= "    Method [ {$userTag} public method {$methodName} ] {\n    }\n";
        }
        $out .= "  }\n";

        $out .= "}\n";

        return $out;
    }

    private static function dumpFromVmEntry(\PHPCompiler\VM\Context $vmCtx, ClassEntry $entry): string
    {
        // Prefer the VM's own Zend-shaped formatter when ReflectionClass is registered.
        $rcClass = $vmCtx->classes[ReflectionSupport::REFLECTION_CLASS] ?? null;
        if (null !== $rcClass) {
            $obj = new \PHPCompiler\VM\ObjectEntry($rcClass);
            $obj->constructed = true;
            $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);

            return ReflectionSupport::classReflectionToString($vmCtx, $obj);
        }

        $tag = '<internal';
        $ext = \PHPCompiler\ext\standard\VmReflection::extensionNameForInternalClass($entry->name);
        if ('' !== $ext) {
            $tag = '<internal:'.$ext.'>';
        }
        $kind = $entry->isInterface ? 'interface' : ($entry->isTrait ? 'trait' : 'class');
        $out = "Class [ {$tag} {$kind} {$entry->name} ] {\n";
        $out .= "\n  - Constants [0] {\n  }\n";
        $out .= "\n  - Static properties [0] {\n  }\n";
        $out .= "\n  - Static methods [0] {\n  }\n";
        $out .= "\n  - Properties [0] {\n  }\n";
        $out .= "\n  - Methods [0] {\n  }\n";
        $out .= "}\n";

        return $out;
    }
}

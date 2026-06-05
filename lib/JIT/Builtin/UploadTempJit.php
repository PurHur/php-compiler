<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM upload temp validation (mirrors VmFs + former phpc_upload_temp.c, #5346).
 *
 * php-src: ext/standard/basic_functions.c — is_uploaded_file, move_uploaded_file
 */
final class UploadTempJit
{
    private const PATH_MAX = 4096;

    private const UPLOAD_PREFIX = 'phpc_upload_';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__phpc_upload_path_has_traversal',
        '__phpc_upload_tmpdir_name',
        '__phpc_upload_is_valid_temp',
        '__compiler_is_uploaded_file',
        '__compiler_move_uploaded_file',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_is_uploaded_file');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);

        self::implementIfMissing($context, '__phpc_upload_path_has_traversal', self::emitPathHasTraversal(...));
        self::implementIfMissing($context, '__phpc_upload_tmpdir_name', self::emitTmpdirName(...));
        self::implementIfMissing($context, '__phpc_upload_is_valid_temp', self::emitIsValidTemp(...));
        self::implementIfMissing($context, '__compiler_is_uploaded_file', self::emitIsUploadedFile(...));
        self::implementIfMissing($context, '__compiler_move_uploaded_file', self::emitMoveUploadedFile(...));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after upload temp JIT link');
            }
            $context->registerFunction($name, $fn);
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = match ($name) {
            '__phpc_upload_path_has_traversal', '__phpc_upload_is_valid_temp',
            '__compiler_is_uploaded_file', '__compiler_move_uploaded_file' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, ...match ($name) {
                    '__phpc_upload_path_has_traversal', '__phpc_upload_is_valid_temp' => [$i8p],
                    '__compiler_is_uploaded_file' => [$strPtr],
                    '__compiler_move_uploaded_file' => [$strPtr, $strPtr],
                    default => [],
                })
            ),
            '__phpc_upload_tmpdir_name' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false)
            ),
            default => throw new \LogicException('Unknown upload temp JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['getenv', $i8p, [$i8p]],
            ['realpath', $i8p, [$i8p, $i8p]],
            ['rename', $i32, [$i8p, $i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['strrchr', $i8p, [$i8p, $i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
    }

    private static function stringDataPtr(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function emitPathHasTraversal(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $path = $fn->getParam(0);
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $slash = $i8->constInt(ord('/'), false);
        $dot = $i8->constInt(ord('.'), false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $nullBb = $fn->appendBasicBlock('traversal_null');
        $loopHead = $fn->appendBasicBlock('traversal_head');
        $context->builder->branchIf($isNull, $nullBb, $loopHead);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($loopHead);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($path, $pSlot);
        $context->builder->store($path, $startSlot);

        $head = $fn->appendBasicBlock('traversal_loop');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ch, $slash);
        $boundary = $context->builder->or($isNul, $isSlash);
        $checkBb = $fn->appendBasicBlock('traversal_check');
        $advanceBb = $fn->appendBasicBlock('traversal_advance');
        $context->builder->branchIf($boundary, $checkBb, $advanceBb);

        $context->builder->positionAtEnd($checkBb);
        $start = $context->builder->load($startSlot);
        $i64 = $context->getTypeFromString('int64');
        $segLen = $context->builder->sub(
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $isDotDot = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $segLen, $i64->constInt(2, false)),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($start), $dot),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->gep($start, $i32->constInt(1, false))),
                    $dot
                )
            )
        );
        $foundBb = $fn->appendBasicBlock('traversal_found');
        $afterCheckBb = $fn->appendBasicBlock('traversal_after_check');
        $context->builder->branchIf($isDotDot, $foundBb, $afterCheckBb);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($afterCheckBb);
        $doneBb = $fn->appendBasicBlock('traversal_done');
        $nextStartBb = $fn->appendBasicBlock('traversal_next_start');
        $context->builder->branchIf($isNul, $doneBb, $nextStartBb);

        $context->builder->positionAtEnd($nextStartBb);
        $nextP = $context->builder->gep($p, $i32->constInt(1, false));
        $context->builder->store($nextP, $pSlot);
        $context->builder->store($nextP, $startSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advanceBb);
        $next = $context->builder->gep($p, $i32->constInt(1, false));
        $context->builder->store($next, $pSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($zero);
    }

    private static function emitTmpdirName(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $fallback = self::literalCstr($context, '/tmp');

        $next = $entry;
        foreach (['TMPDIR', 'TEMP', 'TMP'] as $envName) {
            $check = $fn->appendBasicBlock('tmpdir_check_'.$envName);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $val = $context->builder->call(
                $context->lookupFunction('getenv'),
                self::literalCstr($context, $envName)
            );
            $isNull = $context->builder->icmp(Builder::INT_EQ, $val, $nullPtr);
            $tryNext = $fn->appendBasicBlock('tmpdir_next_'.$envName);
            $testEmpty = $fn->appendBasicBlock('tmpdir_test_empty_'.$envName);
            $context->builder->branchIf($isNull, $tryNext, $testEmpty);

            $context->builder->positionAtEnd($testEmpty);
            $empty = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($val),
                $i8->constInt(0, false)
            );
            $useBb = $fn->appendBasicBlock('tmpdir_use_'.$envName);
            $context->builder->branchIf($empty, $tryNext, $useBb);

            $context->builder->positionAtEnd($useBb);
            $context->builder->returnValue($val);

            $next = $tryNext;
            $context->builder->positionAtEnd($tryNext);
        }

        $context->builder->returnValue($fallback);
    }

    private static function emitIsValidTemp(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $path = $fn->getParam(0);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();
        $prefixLen = \strlen(self::UPLOAD_PREFIX);

        $failBb = $fn->appendBasicBlock('valid_fail');
        $checkEmpty = $fn->appendBasicBlock('valid_check_empty');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $context->builder->branchIf($isNull, $failBb, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($path),
            $i8->constInt(0, false)
        );
        $checkTraversal = $fn->appendBasicBlock('valid_check_traversal');
        $context->builder->branchIf($isEmpty, $failBb, $checkTraversal);

        $context->builder->positionAtEnd($checkTraversal);
        $hasTraversal = $context->builder->call(
            $context->lookupFunction('__phpc_upload_path_has_traversal'),
            $path
        );
        $isTraversal = $context->builder->icmp(Builder::INT_NE, $hasTraversal, $zero);
        $checkPrefix = $fn->appendBasicBlock('valid_check_prefix');
        $context->builder->branchIf($isTraversal, $failBb, $checkPrefix);

        $context->builder->positionAtEnd($checkPrefix);
        $slashLit = $i32->constInt(ord('/'), false);
        $base = $context->builder->call(
            $context->lookupFunction('strrchr'),
            $path,
            $slashLit
        );
        $baseIsNull = $context->builder->icmp(Builder::INT_EQ, $base, $nullPtr);
        $basePtr = $context->builder->select(
            $baseIsNull,
            $path,
            $context->builder->gep($base, $i32->constInt(1, false))
        );
        $prefixOk = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $basePtr,
            self::literalCstr($context, self::UPLOAD_PREFIX),
            $sizeT->constInt($prefixLen, false)
        );
        $hasPrefix = $context->builder->icmp(Builder::INT_EQ, $prefixOk, $zero);
        $realpathBb = $fn->appendBasicBlock('valid_realpath');
        $context->builder->branchIf($hasPrefix, $realpathBb, $failBb);

        $context->builder->positionAtEnd($realpathBb);
        $resolvedTy = $i8->arrayType(self::PATH_MAX);
        $resolvedSlot = $context->builder->alloca($resolvedTy, 1, 'upload_resolved');
        $resolvedBase = $context->builder->inBoundsGEP(
            $resolvedSlot,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $realFrom = $context->builder->call(
            $context->lookupFunction('realpath'),
            $path,
            $resolvedBase
        );
        $realOk = $context->builder->icmp(Builder::INT_NE, $realFrom, $nullPtr);
        $tmpdirBb = $fn->appendBasicBlock('valid_tmpdir');
        $context->builder->branchIf($realOk, $tmpdirBb, $failBb);

        $context->builder->positionAtEnd($tmpdirBb);
        $tmpdirTy = $i8->arrayType(self::PATH_MAX);
        $tmpdirSlot = $context->builder->alloca($tmpdirTy, 1, 'upload_tmpdir');
        $tmpdirBase = $context->builder->inBoundsGEP(
            $tmpdirSlot,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $dirName = $context->builder->call($context->lookupFunction('__phpc_upload_tmpdir_name'));
        $realTmp = $context->builder->call(
            $context->lookupFunction('realpath'),
            $dirName,
            $tmpdirBase
        );
        $tmpOk = $context->builder->icmp(Builder::INT_NE, $realTmp, $nullPtr);
        $prefixCmpBb = $fn->appendBasicBlock('valid_prefix_cmp');
        $context->builder->branchIf($tmpOk, $prefixCmpBb, $failBb);

        $context->builder->positionAtEnd($prefixCmpBb);
        $tmpLen = $context->builder->call($context->lookupFunction('strlen'), $realTmp);
        $cmpLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($tmpLen, $cmpLenSlot);
        $lastIdx = $context->builder->sub($tmpLen, $sizeT->constInt(1, false));
        $lastSlash = $context->builder->load(
            $context->builder->gep($realTmp, $context->builder->trunc($lastIdx, $i32))
        );
        $needsSlash = $context->builder->icmp(Builder::INT_NE, $lastSlash, $i8->constInt(ord('/'), false));
        $appendBb = $fn->appendBasicBlock('valid_append_slash');
        $cmpBb = $fn->appendBasicBlock('valid_cmp');
        $context->builder->branchIf($needsSlash, $appendBb, $cmpBb);

        $context->builder->positionAtEnd($appendBb);
        $slashPtr = $context->builder->gep($realTmp, $context->builder->trunc($tmpLen, $i32));
        $context->builder->store($i8->constInt(ord('/'), false), $slashPtr);
        $nextPtr = $context->builder->gep($slashPtr, $i32->constInt(1, false));
        $context->builder->store($i8->constInt(0, false), $nextPtr);
        $context->builder->store(
            $context->builder->add($tmpLen, $sizeT->constInt(1, false)),
            $cmpLenSlot
        );
        $context->builder->branch($cmpBb);

        $context->builder->positionAtEnd($cmpBb);
        $cmpLen = $context->builder->load($cmpLenSlot);
        $prefixMatch = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $realFrom,
            $realTmp,
            $cmpLen
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $prefixMatch, $zero);
        $successBb = $fn->appendBasicBlock('valid_success');
        $context->builder->branchIf($ok, $successBb, $failBb);

        $context->builder->positionAtEnd($successBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitIsUploadedFile(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $pathObj = $fn->getParam(0);
        $zero = $i32->constInt(0, false);
        $nullPtr = $i8p->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $pathObj, $nullPtr);
        $failBb = $fn->appendBasicBlock('is_uploaded_fail');
        $checkBb = $fn->appendBasicBlock('is_uploaded_check');
        $context->builder->branchIf($isNull, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $valid = $context->builder->call(
            $context->lookupFunction('__phpc_upload_is_valid_temp'),
            self::stringDataPtr($context, $pathObj)
        );
        $context->builder->returnValue($valid);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitMoveUploadedFile(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fromObj = $fn->getParam(0);
        $toObj = $fn->getParam(1);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $failBb = $fn->appendBasicBlock('move_uploaded_fail');
        $checkNull = $fn->appendBasicBlock('move_uploaded_check_null');
        $eitherNull = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $fromObj, $nullPtr),
            $context->builder->icmp(Builder::INT_EQ, $toObj, $nullPtr)
        );
        $context->builder->branchIf($eitherNull, $failBb, $checkNull);

        $context->builder->positionAtEnd($checkNull);
        $from = self::stringDataPtr($context, $fromObj);
        $to = self::stringDataPtr($context, $toObj);
        $validFrom = $context->builder->call(
            $context->lookupFunction('__phpc_upload_is_valid_temp'),
            $from
        );
        $fromOk = $context->builder->icmp(Builder::INT_NE, $validFrom, $zero);
        $checkTo = $fn->appendBasicBlock('move_uploaded_check_to');
        $context->builder->branchIf($fromOk, $checkTo, $failBb);

        $context->builder->positionAtEnd($checkTo);
        $toTraversal = $context->builder->call(
            $context->lookupFunction('__phpc_upload_path_has_traversal'),
            $to
        );
        $toHasTraversal = $context->builder->icmp(Builder::INT_NE, $toTraversal, $zero);
        $toEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($to),
            $i8->constInt(0, false)
        );
        $toBad = $context->builder->or($toHasTraversal, $toEmpty);
        $renameBb = $fn->appendBasicBlock('move_uploaded_rename');
        $context->builder->branchIf($toBad, $failBb, $renameBb);

        $context->builder->positionAtEnd($renameBb);
        $renamed = $context->builder->call(
            $context->lookupFunction('rename'),
            $from,
            $to
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $renamed, $zero);
        $successBb = $fn->appendBasicBlock('move_uploaded_success');
        $context->builder->branchIf($ok, $successBb, $failBb);

        $context->builder->positionAtEnd($successBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }
}

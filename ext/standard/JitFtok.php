<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FtokRuntime;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ftok() via FtokRuntime / FtokJitHelper (#31478, #6296 / #27389). */
final class JitFtok
{
    private const PROJ_LEN_ERROR = 'ftok(): Argument #2 ($project_id) must be a single character';

    private static function emptyPathError(): string
    {
        return VmString::emptyStringArgValueErrorMessageCannot('ftok', 0, 'filename');
    }

    public static function invoke(Context $context, JITVariable $pathArg, JITVariable $projArg): Value
    {
        // Only FtokRuntime — full StringFsDir pull NestedJITs ResolveSidecar (str_replace) (#27389).
        $pathStr = JitStringBuiltinArg::lower($context, $pathArg, 'ftok', 0, 'filename');
        $projStr = JitStringBuiltinArg::lower($context, $projArg, 'ftok', 1, 'project_id');

        self::validatePath($context, $pathArg, $pathStr);
        self::validateProjectId($context, $projArg, $projStr);

        $projByte = self::projectIdByte($context, $projStr);
        $key = FtokRuntime::invoke($context, $pathStr, $projByte);

        $i64 = $context->getTypeFromString('int64');
        $minusOne = $i64->constInt(-1, true);
        $failed = $context->builder->icmp(Builder::INT_EQ, $key, $minusOne);
        $warnBlock = BasicBlockHelper::append($context, 'ftok_warn');
        $okBlock = BasicBlockHelper::append($context, 'ftok_ok');
        $doneBlock = BasicBlockHelper::append($context, 'ftok_done');
        $context->builder->branchIf($failed, $warnBlock, $okBlock);

        $context->builder->positionAtEnd($warnBlock);
        self::emitFtokFailedWarning($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $key);

        return JitValueBox::pointer($context, $slot);
    }

    private static function validatePath(Context $context, JITVariable $arg, Value $pathStr): void
    {
        if (null !== ($arg->compileTimeString ?? null)) {
            if ('' === $arg->compileTimeString) {
                throw new \ValueError(self::emptyPathError());
            }

            return;
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($pathStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $failBlock = BasicBlockHelper::append($context, 'ftok_path_empty_fail');
        $okBlock = BasicBlockHelper::append($context, 'ftok_path_empty_ok');
        $context->builder->branchIf($empty, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        TypeErrorRaise::emitValueError($context, self::emptyPathError());
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function validateProjectId(Context $context, JITVariable $arg, Value $projStr): void
    {
        if (null !== ($arg->compileTimeString ?? null)) {
            if (1 !== \strlen($arg->compileTimeString)) {
                throw new \ValueError(self::PROJ_LEN_ERROR);
            }

            return;
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($projStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $notOne = $context->builder->icmp(Builder::INT_NE, $len, $one);
        $failBlock = BasicBlockHelper::append($context, 'ftok_proj_len_fail');
        $okBlock = BasicBlockHelper::append($context, 'ftok_proj_len_ok');
        $context->builder->branchIf($notOne, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        TypeErrorRaise::emitValueError($context, self::PROJ_LEN_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function projectIdByte(Context $context, Value $projStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $data = $context->builder->structGep($projStr, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $ch = $context->builder->load($data);

        return $context->builder->zExt($ch, $i32);
    }

    private static function emitFtokFailedWarning(Context $context): void
    {
        self::ensureWarningLibc($context);
        StringTriggerError::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $prefix = 'ftok() failed - ';
        $prefixPtr = $context->builder->pointerCast($context->constantFromString($prefix), $i8p);
        $prefixLen = $sizeT->constInt(\strlen($prefix), false);
        $errnoPtr = $context->builder->call($context->lookupFunction('__errno_location'));
        $errnoVal = $context->builder->load($errnoPtr);
        $errMsg = $context->builder->call($context->lookupFunction('strerror'), $errnoVal);
        $msgSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(256));
        $msg = $context->builder->pointerCast($msgSlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msg,
            $sizeT->constInt(256, false),
            $context->builder->pointerCast($context->constantFromString('%s%s'), $i8p),
            $prefixPtr,
            $errMsg
        );
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $sizeT->constInt(255, false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function ensureWarningLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i32Ptr = $i32->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['__errno_location', $i32Ptr, []],
            ['strerror', $i8p, [$i32]],
            ['snprintf', $i32, [$i8p, $sizeT, $i8p]],
        ] as [$name, $ret, $params]) {
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
}

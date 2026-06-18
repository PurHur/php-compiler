<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmNetworkServices;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM network service lookups (mirrors ext/standard/VmNetworkServices.php, #5333).
 *
 * php-src: ext/standard/network.c
 */
final class StringNetworkServicesJit
{
    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__phpc_getprotobyname');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerFunctions($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::ensureLibc($context);
        $tables = VmNetworkServices::buildJitTables();

        self::implementIfMissing($context, '__phpc_getprotobyname', self::emitGetprotobyname(...), $tables['protoByName']);
        self::implementIfMissing($context, '__phpc_getservbyname', self::emitGetservbyname(...), $tables['serviceByName']);

        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction, array): void $emit
     * @param array $tableData
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit, array $tableData): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = $context->lookupFunction($name);
        $emit($context, $fn, $tableData);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerFunctions(Context $context): void
    {
        foreach ([
            '__phpc_getprotobyname',
            '__phpc_getservbyname',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    /**
     * @param list<array{key: string, number: int}> $rows
     */
    private static function emitGetprotobyname(Context $context, LlvmFunction $fn, array $rows): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $nameArg = $fn->getParam(0);
        $out = $fn->getParam(1);
        $nameData = self::stringDataPtr($context, $nameArg);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $writeLong = $context->lookupFunction('__value__writeLong');

        if ([] === $rows) {
            self::writeValueBoolFalse($context, $out);
            $context->builder->returnVoid();

            return;
        }

        $done = $fn->appendBasicBlock('ns_proto_name_done');
        $foundSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $numSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i1->constInt(0, false), $foundSlot);

        foreach ($rows as $idx => $row) {
            $matchBb = $fn->appendBasicBlock('ns_proto_name_match_'.$idx);
            $nextBb = $fn->appendBasicBlock('ns_proto_name_next_'.$idx);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->call($strcasecmpFn, $nameData, self::cstrPtrFromLiteral($context, $row['key'])),
                $i32->constInt(0, false)
            );
            $context->builder->branchIf($isMatch, $matchBb, $nextBb);

            $context->builder->positionAtEnd($matchBb);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->store($i64->constInt($row['number'], false), $numSlot);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($nextBb);
        }

        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        $okBb = $fn->appendBasicBlock('ns_proto_name_ok');
        $failBb = $fn->appendBasicBlock('ns_proto_name_fail');
        $exitBb = $fn->appendBasicBlock('ns_proto_name_exit');
        $found = $context->builder->load($foundSlot);
        $context->builder->branchIf($found, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        self::writeValueBoolFalse($context, $out);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($writeLong, $out, $context->builder->load($numSlot));
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);
        $context->builder->returnVoid();
    }

    /**
     * @param list<array{service: string, protocol: string, port: int}> $rows
     */
    private static function emitGetservbyname(Context $context, LlvmFunction $fn, array $rows): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $serviceArg = $fn->getParam(0);
        $protoArg = $fn->getParam(1);
        $out = $fn->getParam(2);
        $serviceData = self::stringDataPtr($context, $serviceArg);
        $protoData = self::stringDataPtr($context, $protoArg);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $writeLong = $context->lookupFunction('__value__writeLong');

        if ([] === $rows) {
            self::writeValueBoolFalse($context, $out);
            $context->builder->returnVoid();

            return;
        }

        $done = $fn->appendBasicBlock('ns_svc_name_done');
        $foundSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $portSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i1->constInt(0, false), $foundSlot);

        foreach ($rows as $idx => $row) {
            $matchBb = $fn->appendBasicBlock('ns_svc_name_match_'.$idx);
            $nextBb = $fn->appendBasicBlock('ns_svc_name_next_'.$idx);
            $svcOk = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->call($strcasecmpFn, $serviceData, self::cstrPtrFromLiteral($context, $row['service'])),
                $i32->constInt(0, false)
            );
            $protoOk = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->call($strcasecmpFn, $protoData, self::cstrPtrFromLiteral($context, $row['protocol'])),
                $i32->constInt(0, false)
            );
            $isMatch = $context->builder->and($svcOk, $protoOk);
            $context->builder->branchIf($isMatch, $matchBb, $nextBb);

            $context->builder->positionAtEnd($matchBb);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->store($i64->constInt($row['port'], false), $portSlot);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($nextBb);
        }

        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        $okBb = $fn->appendBasicBlock('ns_svc_name_ok');
        $failBb = $fn->appendBasicBlock('ns_svc_name_fail');
        $exitBb = $fn->appendBasicBlock('ns_svc_name_exit');
        $found = $context->builder->load($foundSlot);
        $context->builder->branchIf($found, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        self::writeValueBoolFalse($context, $out);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($writeLong, $out, $context->builder->load($portSlot));
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);
        $context->builder->returnVoid();
    }

    private static function cstrPtrFromLiteral(Context $context, string $literal): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($literal),
            $context->getTypeFromString('int8*')
        );
    }

    private static function writeValueBoolFalse(Context $context, Value $out): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
    }

    private static function stringDataPtr(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        return $context->builder->inBoundsGEP(
            $context->builder->structGep($str, $map['value']),
            $zero
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'strcasecmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return;
        }
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}

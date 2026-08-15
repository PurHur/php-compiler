<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_addrinfo_* via SocketAddrinfoJitHelper (#31357 / #6064).
 *
 * Lookup builds AddressInfo[] like {@see GethostbynamelRuntime}; connect/bind reuse
 * {@see SocketCreateJitHelper::registerOwnedArgv} owned-fd map.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_*)
 */
final class SocketAddrinfoRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketAddrinfoJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketAddrinfoJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::lookupCountConstArgv',
        self::H.'::lookupCountArgv',
        self::H.'::registerArgv',
        self::H.'::explainLoadArgv',
        self::H.'::explainFlagsArgv',
        self::H.'::explainFamilyArgv',
        self::H.'::explainSocktypeArgv',
        self::H.'::explainProtocolArgv',
        self::H.'::explainSinPortArgv',
        self::H.'::explainSinAddrArgv',
        self::H.'::explainAddrIsInet6Argv',
        self::H.'::socketFdArgv',
        self::H.'::domainForHandleArgv',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_addrinfo_lookup');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibc($context);
        SocketCreateRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementLookupCountBridge($context);
        self::implementLookupBridge($context);
        self::implementExplainBridges($context);
        self::implementSocketFdBridge($context);
        self::implementDomainBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementLookupCountBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        // Zero-arg NestedJIT — avoids string ABI coercion issues under thin AOT (#31357).
        self::bridgeScalar(
            $context,
            '__compiler_socket_addrinfo_lookup_count',
            self::H.'::lookupCountConstArgv',
            [],
            $i64
        );
    }

    private static function implementLookupBridge(Context $context): void
    {
        $abiName = '__compiler_socket_addrinfo_lookup';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $voidp = $context->getTypeFromString('void')->pointerType(0);

        $ft = $context->context->functionType(
            $htPtr,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i64,
            $i64
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('sai_lookup_entry');
        $emptyBb = $fn->appendBasicBlock('sai_lookup_empty');
        $buildInitBb = $fn->appendBasicBlock('sai_lookup_build_init');
        $context->builder->positionAtEnd($entry);

        $countI64 = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_lookup_count')
        );
        $count = $countI64->typeOf() === $sizeT
            ? $countI64
            : $context->builder->zExt($countI64, $sizeT);
        $hasAny = $context->builder->icmp(
            Builder::INT_SGT,
            $count,
            $sizeT->constInt(0, false)
        );
        $context->builder->branchIf($hasAny, $buildInitBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($buildInitBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $iSlot = $context->builder->alloca($sizeT, 1, 'sai_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('sai_lookup_loop_head');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $loopDone = $context->builder->icmp(Builder::INT_EQ, $i, $count);
        $loopDoneBb = $fn->appendBasicBlock('sai_lookup_loop_done');
        $loopBodyBb = $fn->appendBasicBlock('sai_lookup_loop_body');
        $context->builder->branchIf($loopDone, $loopDoneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $indexI64 = $i->typeOf() === $i64 ? $i : $context->builder->zExt($i, $i64);
        $objectType = $context->type->object;
        $classId = $objectType->lookup('AddressInfo');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($obj, $voidp),
            $i64
        );
        $context->builder->call(
            self::helperFunction($context, self::H.'::registerArgv'),
            $objAddr,
            $indexI64
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $indexI64,
            $obj
        );
        $context->builder->store(
            $context->builder->add($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDoneBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
        unset($voidTy, $objPtr);
    }

    private static function implementExplainBridges(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_load', self::H.'::explainLoadArgv', [$i64], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_flags', self::H.'::explainFlagsArgv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_family', self::H.'::explainFamilyArgv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_socktype', self::H.'::explainSocktypeArgv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_protocol', self::H.'::explainProtocolArgv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_sin_port', self::H.'::explainSinPortArgv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_inet6', self::H.'::explainAddrIsInet6Argv', [], $i64);
        self::bridgeScalar($context, '__compiler_socket_addrinfo_explain_sin_addr', self::H.'::explainSinAddrArgv', [], $strPtr);
    }

    private static function implementSocketFdBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::bridgeScalar(
            $context,
            '__compiler_socket_addrinfo_socket_fd',
            self::H.'::socketFdArgv',
            [$i64, $i64],
            $i64
        );
    }

    private static function implementDomainBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::bridgeScalar(
            $context,
            '__compiler_socket_addrinfo_domain',
            self::H.'::domainForHandleArgv',
            [$i64],
            $i64
        );
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function bridgeScalar(
        Context $context,
        string $abiName,
        string $helperLogical,
        array $paramTypes,
        $returnType
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $entryName = \str_replace('__compiler_', '', $abiName).'_bridge_entry';
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            $entryName,
            $paramTypes,
            $returnType,
            $helperLogical,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31357',
            true
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31357');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31357',
            true
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $objPtr = $context->getTypeFromString('__object__*');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setObjectAt',
            $context->context->functionType($voidTy, false, $htPtr, $i64, $objPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            '__compiler_socket_addrinfo_lookup',
            '__compiler_socket_addrinfo_lookup_count',
            '__compiler_socket_addrinfo_explain_load',
            '__compiler_socket_addrinfo_explain_flags',
            '__compiler_socket_addrinfo_explain_family',
            '__compiler_socket_addrinfo_explain_socktype',
            '__compiler_socket_addrinfo_explain_protocol',
            '__compiler_socket_addrinfo_explain_sin_port',
            '__compiler_socket_addrinfo_explain_sin_addr',
            '__compiler_socket_addrinfo_explain_inet6',
            '__compiler_socket_addrinfo_socket_fd',
            '__compiler_socket_addrinfo_domain',
        ] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after SocketAddrinfoRuntime (#31357)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}

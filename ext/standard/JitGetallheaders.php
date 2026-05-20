<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for getallheaders() — copy HTTP_* / CONTENT_* from sg_SERVER.
 */
final class JitGetallheaders
{
    public static function invoke(Context $context): Value
    {
        $result = HashTableHelper::alloc($context);
        $serverVar = SuperglobalInit::load($context, '_SERVER');
        $serverPtr = $context->helper->loadValue($serverVar);

        $vmServer = $context->runtime->vmContext->getSuperglobal('_SERVER');
        if (null === $vmServer || VMVariable::TYPE_ARRAY !== $vmServer->type) {
            return $result;
        }
        $table = $vmServer->toArray();
        if (!$table instanceof HashTable) {
            return $result;
        }

        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (VMVariable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $serverKey = $keyVar->toString();
            $headerName = Superglobals::serverKeyToHeaderName($serverKey);
            if (null === $headerName) {
                continue;
            }
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_STRING !== $resolved->type) {
                continue;
            }

            $serverKeyStr = $context->builder->load(
                $context->constantStringFromString($serverKey)
            );
            $headerKeyStr = $context->builder->load(
                $context->constantStringFromString($headerName)
            );
            $valueBox = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringKeyValue'),
                $serverPtr,
                $serverKeyStr
            );
            $valueStr = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valueBox
            );
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $result,
                $headerKeyStr,
                $valueStr
            );
        }

        return $result;
    }
}

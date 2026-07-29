<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_version() — libcurl version array (php-src ext/curl/interface.c; #16659, #24463).
 */
final class curl_version extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_version');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'curl_version() expects at most 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $age = null;
        if (isset($frame->calledArgs[0])) {
            $age = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'curl_version', 0, 'age');
        }
        $frame->returnVar->array(VmCurlCore::versionArray($age));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'curl_version() expects at most 1 argument, %d given',
                \count($args)
            ));
        }
        // Static Zend-shaped payload — materialize VM HashTable into LLVM (#24463 AOT path).
        $ht = HashTableHelper::variableFromVmHashTable($context, VmCurlCore::versionArray(null));

        return $ht->value;
    }
}

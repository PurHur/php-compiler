<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_version() — libcurl version array (php-src ext/curl/interface.c / curl.stub.php; #16659, #24463, #25585).
 *
 * Modern php-src takes no parameters (removed optional $version / CURLVERSION_* age selector).
 */
final class curl_version extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_version');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(\sprintf(
                'curl_version() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmCurlCore::versionArray(null));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(\sprintf(
                'curl_version() expects exactly 0 arguments, %d given',
                \count($args)
            ));
        }
        // Static Zend-shaped payload — materialize VM HashTable into LLVM (#24463 AOT path).
        $ht = HashTableHelper::variableFromVmHashTable($context, VmCurlCore::versionArray(null));

        return $ht->value;
    }
}

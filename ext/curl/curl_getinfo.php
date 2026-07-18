<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_getinfo() — transfer info (php-src ext/curl/interface.c; #3325).
 */
final class curl_getinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_getinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                $argc < 1
                    ? 'curl_getinfo() expects at least 1 argument, %d given'
                    : 'curl_getinfo() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_getinfo', 1);
        $option = null;
        if ($argc >= 2) {
            $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'curl_getinfo', 2, 'option');
        }
        $info = VmCurlEasy::getinfo($easy, $option);
        self::assignReturn($frame->returnVar, $info);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_getinfo() is not implemented for JIT in this compiler build (issue #3325)');
    }

    private static function assignReturn(Variable $returnVar, mixed $info): void
    {
        if (false === $info) {
            $returnVar->bool(false);

            return;
        }
        if (\is_int($info)) {
            $returnVar->int($info);

            return;
        }
        if (\is_string($info)) {
            $returnVar->string($info);

            return;
        }
        if (\is_array($info)) {
            $returnVar->array(self::arrayToHashTable($info));

            return;
        }
        $returnVar->null();
    }

    /** @param array<string, mixed> $info */
    private static function arrayToHashTable(array $info): HashTable
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } elseif (\is_array($value)) {
                $slot->array(new HashTable());
            } else {
                $slot->null();
            }
            $ht->add($key, $slot);
        }

        return $ht;
    }
}

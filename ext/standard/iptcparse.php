<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** iptcparse() — parse binary IPTC data (ext/standard/iptc.c; issue #6104). */
final class iptcparse extends Internal
{
    public function __construct()
    {
        parent::__construct('iptcparse');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'iptcparse() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'iptcparse',
            0,
            'iptc_block'
        );
        $parsed = VmIptc::parse($data);
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->array(self::parsedToHashTable($parsed));
    }

    /** @param array<string, list<string>> $parsed */
    public static function parsedToHashTable(array $parsed): HashTable
    {
        $ht = new HashTable();
        foreach ($parsed as $key => $values) {
            $list = new HashTable();
            foreach ($values as $value) {
                $cell = new Variable();
                $cell->string($value);
                $list->append($cell);
            }
            $bucket = new Variable();
            $bucket->array($list);
            $ht->add($key, $bucket);
        }

        return $ht;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('iptcparse() expects exactly 1 argument in this compiler build');
        }

        return JitIptcParse::invoke($context, $args[0]);
    }
}

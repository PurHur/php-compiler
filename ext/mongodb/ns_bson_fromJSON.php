<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** MongoDB\BSON\fromJSON() — JSON document → PHP value (#27875). */
final class ns_bson_fromJSON extends Internal
{
    public function __construct()
    {
        parent::__construct('MongoDB\\BSON\\fromJSON');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('MongoDB\\BSON\\fromJSON() expects exactly 1 argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $json = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'MongoDB\\BSON\\fromJSON',
            0,
            'json'
        );
        self::assignPhpValue($frame->returnVar, VmMongodbTypes::fromJson($json));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('MongoDB\\BSON\\fromJSON() is VM-only in this compiler build (#27875)');
    }

    /** @param mixed $value */
    private static function assignPhpValue(Variable $dest, $value): void
    {
        if (null === $value) {
            $dest->null();

            return;
        }
        if (\is_bool($value)) {
            $dest->bool($value);

            return;
        }
        if (\is_int($value)) {
            $dest->int($value);

            return;
        }
        if (\is_float($value)) {
            $dest->float($value);

            return;
        }
        if (\is_string($value)) {
            $dest->string($value);

            return;
        }
        if (\is_array($value)) {
            $ht = new HashTable();
            foreach ($value as $k => $v) {
                $child = new Variable();
                self::assignPhpValue($child, $v);
                if (\is_int($k)) {
                    $ht->append($child);
                } else {
                    $ht->add((string) $k, $child);
                }
            }
            $dest->array($ht);

            return;
        }
        if (\is_object($value)) {
            $ht = new HashTable();
            foreach (get_object_vars($value) as $k => $v) {
                $child = new Variable();
                self::assignPhpValue($child, $v);
                $ht->add((string) $k, $child);
            }
            $dest->array($ht);

            return;
        }
        $dest->null();
    }
}

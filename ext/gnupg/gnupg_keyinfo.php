<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** gnupg_keyinfo() (#6668). */
final class gnupg_keyinfo extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_keyinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_keyinfo() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_keyinfo', 1);
        $pattern = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_keyinfo', 2, 'pattern');
        $secretOnly = false;
        if (3 === $argc) {
            $secretOnly = (bool) $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $result = VmGnupgCore::keyinfo($object, $pattern, $secretOnly);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $row) {
            $wrap = new Variable();
            $wrap->array(self::phpArrayToHashTable($row));
            $ht->append($wrap);
        }
        $frame->returnVar->array($ht);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function phpArrayToHashTable(array $data): HashTable
    {
        $ht = new HashTable();
        if (array_is_list($data)) {
            foreach ($data as $value) {
                $cell = new Variable();
                if (\is_array($value)) {
                    $cell->array(self::phpArrayToHashTable($value));
                } elseif (\is_bool($value)) {
                    $cell->bool($value);
                } elseif (\is_int($value)) {
                    $cell->int($value);
                } else {
                    $cell->string((string) $value);
                }
                $ht->append($cell);
            }

            return $ht;
        }
        foreach ($data as $key => $value) {
            $cell = new Variable();
            if (\is_array($value)) {
                $cell->array(self::phpArrayToHashTable($value));
            } elseif (\is_bool($value)) {
                $cell->bool($value);
            } elseif (\is_int($value)) {
                $cell->int($value);
            } else {
                $cell->string((string) $value);
            }
            $ht->set((string) $key, $cell);
        }

        return $ht;
    }
}

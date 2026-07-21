<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** gnupg_verify() (#6668). */
final class gnupg_verify extends GnupgFunction
{
    public function __construct()
    {
        parent::__construct('gnupg_verify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'gnupg_verify() expects 3 or 4 arguments, %d given',
                $argc
            ));
        }
        $object = VmGnupgArg::requireGnupg($frame->calledArgs[0], 'gnupg_verify', 1);
        $signedText = VmGnupgArg::requireString($frame->calledArgs[1], 'gnupg_verify', 2, 'text');
        $plaintextOut = 4 === $argc ? $frame->calledArgs[3] : null;
        $result = VmGnupgCore::verify($object, $signedText, $frame->calledArgs[2], $plaintextOut);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $row) {
            $inner = new HashTable();
            foreach ($row as $k => $v) {
                $cell = new Variable();
                if (\is_int($v)) {
                    $cell->int($v);
                } else {
                    $cell->string((string) $v);
                }
                $inner->set((string) $k, $cell);
            }
            $wrap = new Variable();
            $wrap->array($inner);
            $ht->append($wrap);
        }
        $frame->returnVar->array($ht);
    }
}

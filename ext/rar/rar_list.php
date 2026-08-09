<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** rar_list() — RarArchive::getEntries() (PECL rar rararch.c; #27878). */
final class rar_list extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_list');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_list', 1);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('rar_list() requires an active VM context');
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_list()');
            $entries = VmRar::getEntries($archive, $frame->vmContext);
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($entries as $entry) {
            $slot = new Variable();
            $slot->object($entry);
            $ht->append($slot);
        }
        $frame->returnVar->array($ht);
    }
}

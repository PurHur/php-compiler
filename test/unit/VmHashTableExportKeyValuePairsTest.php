<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** HashTable::exportKeyValuePairs for php-in-PHP JIT helpers (#12908). */
final class VmHashTableExportKeyValuePairsTest extends TestCase
{
    public function testExportKeyValuePairsMatchesIterateKeyed(): void
    {
        $ht = new HashTable();
        $from = new Variable();
        $from->string('to');
        $ht->add('from', $from);

        $zero = new Variable();
        $zero->string('zero');
        $ht->addIndex(0, $zero);

        $fromExport = $ht->exportKeyValuePairs(true);
        $fromIterate = iterator_to_array($ht->iterateKeyed(true), false);

        $this->assertCount(\count($fromIterate), $fromExport);
        foreach ($fromExport as $i => [$keyVar, $valueVar]) {
            [$expectKey, $expectVal] = $fromIterate[$i];
            $this->assertSame($expectKey->toString(), $keyVar->toString());
            $this->assertSame($expectVal->toString(), $valueVar->toString());
        }
    }
}

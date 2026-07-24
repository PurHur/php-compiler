<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

final class VmPregMatchesTest extends TestCase
{
    public function testHostMatchesMaterializesWithoutError(): void
    {
        $ht = VmPregMatches::hostMatchesToHashTable(
            [0 => 'a', 'n' => 'a', 1 => 'a'],
            0
        );
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $ht);
    }

    public function testHostMatchesMaterializesNamedOffsetCapture(): void
    {
        $ht = VmPregMatches::hostMatchesToHashTable(
            [0 => ['a', 2], 'n' => ['a', 2], 1 => ['a', 2]],
            StdlibConstants::PREG_OFFSET_CAPTURE
        );
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $ht);
    }

    public function testHostMatchAllMaterializesWithoutError(): void
    {
        $ht = VmPregMatches::hostMatchAllToHashTable(
            [1 => [['1', 1], ['2', 4]]],
            StdlibConstants::PREG_PATTERN_ORDER | StdlibConstants::PREG_OFFSET_CAPTURE
        );
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $ht);
    }

    public function testHostMatchAllPatternOrderKeepsNamedKeys(): void
    {
        $ht = VmPregMatches::hostMatchAllToHashTable(
            [
                0 => ['12', '34'],
                1 => ['12', '34'],
                'n' => ['12', '34'],
            ],
            StdlibConstants::PREG_PATTERN_ORDER
        );
        $named = $ht->find('n');
        $this->assertNotNull($named);
        $this->assertSame(\PHPCompiler\VM\Variable::TYPE_ARRAY, $named->resolveIndirect()->type);
    }
}

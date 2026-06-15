<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

final class VmPregMatchesTest extends TestCase
{
    public function testHostMatchesMaterializesWithoutError(): void
    {
        $ht = VmPregMatches::hostMatchesToHashTable(
            [0 => ['a', 2], 1 => ['a', 2]],
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
}

<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Printer as OpCodePrinter;
use PHPUnit\Framework\TestCase;

/**
 * Default PHPTypes worklist resolver must lower to the same opcodes as the legacy round loop (#36225).
 */
final class TypeReconstructorWorklistOpcodeEquivalenceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPTYPES_RESOLVER_WORKLIST');
        putenv('PHPTYPES_RESOLVER_LEGACY');
    }

    /** @return list<array{0: string}> */
    public static function corpusProvider(): array
    {
        $root = \dirname(__DIR__, 2);
        $rels = [
            'examples/000-HelloWorld/example.php',
            'lib/Printer.php',
            'lib/Block.php',
            'ext/standard/Module.php',
            'bin/print.php',
            'test/selfhost/compiler_lib_spine_smoke/main.php',
        ];
        $out = [];
        foreach ($rels as $rel) {
            $abs = $root.'/'.$rel;
            if (is_readable($abs)) {
                $out[$rel] = [$abs];
            }
        }

        return $out;
    }

    private function opcodes(string $file, bool $legacy): string
    {
        if ($legacy) {
            putenv('PHPTYPES_RESOLVER_WORKLIST=0');
            putenv('PHPTYPES_RESOLVER_LEGACY=1');
        } else {
            putenv('PHPTYPES_RESOLVER_WORKLIST=1');
            putenv('PHPTYPES_RESOLVER_LEGACY');
        }
        $runtime = new Runtime();
        $code = (string) file_get_contents($file);
        $block = $runtime->compile($runtime->parse($code, $file));

        return (new OpCodePrinter())->print($block);
    }

    /**
     * @dataProvider corpusProvider
     */
    public function testDefaultWorklistMatchesLegacyOpcodes(string $file): void
    {
        $legacy = $this->opcodes($file, true);
        $worklist = $this->opcodes($file, false);
        $this->assertSame(
            $legacy,
            $worklist,
            basename($file).': worklist opcodes diverged from legacy round loop'
        );
    }
}

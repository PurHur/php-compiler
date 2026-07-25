<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Printer as OpCodePrinter;
use PHPUnit\Framework\TestCase;

/**
 * Default PHPCFG Simplifier use-chain path must lower to the same opcodes as legacy (#23056).
 *
 * CFG pretty-print / inferred type labels can still differ (replacement ORDER); the compile
 * spine cares about opcode identity. Opt out remains PHPCFG_SIMPLIFIER_USECHAIN=0 /
 * PHPCFG_SIMPLIFIER_LEGACY=1.
 */
final class SimplifierUseChainOpcodeEquivalenceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPCFG_SIMPLIFIER_USECHAIN');
        putenv('PHPCFG_SIMPLIFIER_LEGACY');
    }

    /** @return list<array{0: string}> */
    public static function corpusProvider(): array
    {
        $root = \dirname(__DIR__, 2);
        $rels = [
            'examples/000-HelloWorld/example.php',
            'lib/Printer.php',
            'ext/standard/Module.php',
            'bin/print.php',
            'bin/vm.php',
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
            putenv('PHPCFG_SIMPLIFIER_USECHAIN=0');
            putenv('PHPCFG_SIMPLIFIER_LEGACY=1');
        } else {
            putenv('PHPCFG_SIMPLIFIER_USECHAIN');
            putenv('PHPCFG_SIMPLIFIER_LEGACY');
        }
        $runtime = new Runtime();
        $code = (string) file_get_contents($file);
        $block = $runtime->compile($runtime->parse($code, $file));

        return (new OpCodePrinter())->print($block);
    }

    /**
     * @dataProvider corpusProvider
     */
    public function testDefaultUseChainMatchesLegacyOpcodes(string $file): void
    {
        $legacy = $this->opcodes($file, true);
        $usechain = $this->opcodes($file, false);
        $this->assertSame(
            $legacy,
            $usechain,
            basename($file).': use-chain opcodes diverged from legacy CFG walk'
        );
    }

    public function testLegacyEnvForcesCfgWalkPath(): void
    {
        // Smoke: both opt-out knobs are accepted without throwing on a tiny script.
        $root = \dirname(__DIR__, 2);
        $file = $root.'/examples/000-HelloWorld/example.php';
        putenv('PHPCFG_SIMPLIFIER_USECHAIN=0');
        $a = $this->opcodes($file, true);
        putenv('PHPCFG_SIMPLIFIER_USECHAIN');
        putenv('PHPCFG_SIMPLIFIER_LEGACY=1');
        $runtime = new Runtime();
        $b = (new OpCodePrinter())->print(
            $runtime->compile($runtime->parse((string) file_get_contents($file), $file))
        );
        $this->assertSame($a, $b);
    }
}

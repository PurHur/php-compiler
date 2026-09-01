<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Default PHPTYPES worklist resolver must lower to the same opcodes as the legacy round loop (#36225).
 */
final class TypeReconstructorWorklistOpcodeEquivalenceTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function corpusProvider(): array
    {
        $root = \dirname(__DIR__, 2);
        $rels = [
            'examples/000-HelloWorld/example.php',
            'lib/Printer.php',
            'bin/print.php',
            'bin/vm.php',
            'lib/Lint/Issue.php',
            'lib/Lint/Severity.php',
            'ext/standard/strings.php',
            'ext/standard/array.php',
            'lib/Value.php',
            'lib/Operand.php',
        ];
        $out = [];
        foreach ($rels as $rel) {
            $abs = $root.'/'.$rel;
            if (is_readable($abs)) {
                $out[$rel] = [$rel, $abs];
            }
        }

        return $out;
    }

    private function opcodesInSubprocess(string $file, string $mode): string
    {
        $root = \dirname(__DIR__, 2);
        $helper = $root.'/test/support/resolver_opcode_dump.php';
        $cmd = [\PHP_BINARY, $helper, $file, $mode];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        if (! \is_resource($proc)) {
            $this->fail('proc_open failed for '.$file.' ('.$mode.')');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, trim($stderr) !== '' ? trim($stderr) : 'opcode dump failed');

        return (string) $stdout;
    }

    /**
     * @dataProvider corpusProvider
     */
    public function testDefaultWorklistMatchesLegacyRoundOpcodes(string $rel, string $file): void
    {
        $legacy = $this->opcodesInSubprocess($file, 'legacy');
        $worklist = $this->opcodesInSubprocess($file, 'worklist');
        $this->assertSame(
            $legacy,
            $worklist,
            $rel.': worklist resolver opcodes diverged from legacy round loop'
        );
    }
}

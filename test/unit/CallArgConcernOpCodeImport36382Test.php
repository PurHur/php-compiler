<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Split-TU CallArg Concerns must import PHPCompiler\OpCode — bare OpCode resolves
 * under Compiler\Concern and fatals NestedJIT during Slim project AOT (#36382).
 *
 * @coversNothing
 */
final class CallArgConcernOpCodeImport36382Test extends TestCase
{
    public function testCallArgConcernsThatEmitOpCodeImportPhpCompilerOpCode(): void
    {
        $dir = dirname(__DIR__, 2).'/lib/Compiler/Concern';
        $missing = [];
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'new OpCode') && !str_contains($src, 'OpCode::')) {
                continue;
            }
            if (!str_contains($src, 'use PHPCompiler\\OpCode;')) {
                // Allow fully-qualified uses only
                $bare = false;
                foreach (explode("\n", $src) as $line) {
                    $t = ltrim($line);
                    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
                        continue;
                    }
                    if ((str_contains($line, 'new OpCode') || str_contains($line, 'OpCode::'))
                        && !str_contains($line, 'PHPCompiler\\OpCode')) {
                        $bare = true;
                        break;
                    }
                }
                if ($bare) {
                    $missing[] = basename($path);
                }
            }
        }
        $this->assertSame([], $missing, 'Concerns with bare OpCode missing use PHPCompiler\\OpCode');
    }
}

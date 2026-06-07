<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types TypeReconstructor must compile int|never signatures (#7451, #7449, #7414).
 *
 * @covers issue #7451
 */
final class NeverUnionCompileTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testMaintainerNeverUnionReturnReproCompilesAndRuns(): void
    {
        $repro = self::$root.'/test/repro/maintainer_never_union_return.php';
        self::assertFileIsReadable($repro);

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($repro), $repro));
        $this->assertSame("ok:unreachable\ng:7\n", ob_get_clean());
    }

    public function testIntNeverReturnTypeCompilesWithoutUnionOpTypeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): int|never {
    throw new Exception('x');
}
PHP;
        try {
            $compiled = $runtime->parseAndCompile($code, 'int_never_return.php');
        } catch (\LogicException $e) {
            self::fail('TypeReconstructor must handle Op\\Type\\Union_: '.$e->getMessage());
        }
        self::assertNotNull($compiled);
    }

    public function testIntNeverParamTypeCompilesWithoutUnionOpTypeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(int|never $x): int {
    return $x;
}
echo g(3);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'int_never_param.php'));
        $this->assertSame('3', ob_get_clean());
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * bootstrap-aot-link compile guard: array_value_box uses array_push on boxed property (#1492).
 *
 * @group aot-lint
 */
final class BootstrapAotArrayValueBoxCompileTest extends TestCase
{
    public function testArrayValueBoxBootstrapTargetCompilesToAotBinary(): void
    {
        $repo = \realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/bootstrap-aot/array_value_box.php';
        $out = $repo.'/build/test-bootstrap-aot-array-value-box';
        if (!\is_dir($repo.'/build')) {
            \mkdir($repo.'/build', 0775, true);
        }
        if (\is_file($out)) {
            \unlink($out);
        }

        $cmd = \sprintf(
            '%s %s/bin/compile.php -o %s %s 2>&1',
            \PHP_BINARY,
            \escapeshellarg($repo),
            \escapeshellarg($out),
            \escapeshellarg($source)
        );
        \exec($cmd, $output, $code);
        $this->assertSame(0, $code, \implode("\n", $output));
        $this->assertFileExists($out);
        $this->assertTrue(\is_executable($out));
    }
}

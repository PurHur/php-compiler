<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMException from DOMNode::removeChild is catchable as Throwable (#33596).
 *
 * Catch dispatch is lowered before the try body; DOMException must be registered
 * when building Throwable instanceof arms (peer JsonException #27623).
 *
 * @group llvm
 */
final class DomExceptionCatchThrowableAotTest extends TestCase
{
    public function testRemoveChildAttrCaughtAsThrowable(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33596_dom_removechild_attr_aot.php');
    }

    public function testThrowNewDomExceptionCaughtAsThrowable(): void
    {
        $src = <<<'PHP'
<?php
declare(strict_types=1);
try {
    throw new DOMException('probe', 8);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $path = sys_get_temp_dir().'/dom_exc_throwable_'.getmypid().'.php';
        file_put_contents($path, $src);
        try {
            $this->assertAotMatchesZend($path);
        } finally {
            @unlink($path);
        }
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_exc_throwable_aot_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}

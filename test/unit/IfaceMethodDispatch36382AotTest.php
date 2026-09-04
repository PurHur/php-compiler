<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT: interface/abstract-typed property method calls + related Slim blockers.
 *
 * php-src: Zend/zend_object_handlers.c zend_std_get_method
 *
 * @group aot
 */
final class IfaceMethodDispatch36382AotTest extends TestCase
{
    public function testInterfaceTypedPropertyMethodCall(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_iface_method_call.php', 'ok');
    }

    public function testAbstractTypedPropertyMethodCall(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_abstract_method_call.php', 'ok');
    }

    public function testNoImplementorContainerHasCompiles(): void
    {
        // Slim CallableResolver shape: optional DI container, no implementor in TU.
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_callable_resolver_no_implementor.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'iface36382ni_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $ec, $joined);
        $this->assertFileExists($out);
        @unlink($out);
        $this->assertStringNotContainsString('undefined method', strtolower($joined));
    }

    private function assertAotEchoes(string $relSrc, string $expected): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/'.$relSrc;
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'iface36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame($expected, trim(implode("\n", $runLines)));
    }
}

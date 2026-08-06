<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\InfoJitHelper;
use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\ext\standard\VmPhpCoreConstants;
use PHPUnit\Framework\TestCase;

/** phpversion() and PHP_VERSION must agree (#11470, ext/standard/info.c). */
final class PhpVersionParityTest extends TestCase
{
    public function testVmPhpVersionMatchesPhpVersionConstant(): void
    {
        $constant = VmPhpCoreConstants::fetch('PHP_VERSION');
        $this->assertNotNull($constant);
        $reported = CompilerVersion::reportedPhpVersion();
        $this->assertSame($reported, $constant->toString());
        $this->assertSame(VmInfo::phpversion(), $constant->toString());
        $this->assertSame(0, version_compare(VmInfo::phpversion(), $constant->toString()));
    }

    public function testInfoJitHelperUsesReportedPhpVersion(): void
    {
        $reported = CompilerVersion::reportedPhpVersion();
        $this->assertSame($reported, InfoJitHelper::phpversion(null));
        $this->assertSame($reported, InfoJitHelper::phpversion('Core'));
        $this->assertSame($reported, InfoJitHelper::phpversion('standard'));
        $this->assertStringNotContainsString(
            'VERSION_STRING',
            (string) file_get_contents(__DIR__.'/../../ext/standard/InfoJitHelper.php')
        );
    }

    /** Bundled xml family tracks reported PHP version, not forward VERSION (#25819). */
    public function testXmlFamilyPhpversionMatchesReported(): void
    {
        // ModuleRegistry populates when Runtime loads core modules.
        new \PHPCompiler\Runtime();
        $reported = CompilerVersion::reportedPhpVersion();
        $this->assertNotSame(CompilerVersion::VERSION, $reported);
        $this->assertSame($reported, VmInfo::phpversion('xml'));
        $this->assertSame($reported, VmInfo::phpversion('libxml'));
        $this->assertSame($reported, VmInfo::phpversion('simplexml'));
        $this->assertSame($reported, VmInfo::phpversion('xmlreader'));
        $this->assertSame($reported, VmInfo::phpversion('xmlwriter'));
        $this->assertSame($reported, InfoJitHelper::phpversion('xml'));
        $this->assertSame('20031129', VmInfo::phpversion('dom'));
    }

    /** Zend stubs: phpversion(?string $extension = null): string|false (#28004). */
    public function testVmPhpversionReflectionMatchesZendStub(): void
    {
        $code = <<<'PHP'
<?php
$r = new ReflectionFunction('phpversion');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), ' type=', $p->getType(), ' opt=', (int) $p->isOptional();
if ($p->isDefaultValueAvailable()) {
    echo ' def=', var_export($p->getDefaultValue(), true);
} else {
    echo ' def=N/A';
}
echo ' return=', $r->getReturnType(), "\n";
echo 'bare=', is_string(phpversion()) ? 'ok' : 'bad', "\n";
echo 'named=', is_string(phpversion(extension: 'json')) ? 'ok' : 'bad', "\n";
echo 'unknown=', var_export(phpversion('___no_such_ext___'), true), "\n";
PHP;
        $rt = new \PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'phpversion_reflect.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "name=extension type=?string opt=1 def=NULL return=string|false\n"
            ."bare=ok\nnamed=ok\nunknown=false\n",
            ob_get_clean()
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Dom\HTMLDocument::createFromString + getElementById (#35792).
 *
 * @see php-src ext/dom/html_document.c Dom\HTMLDocument::createFromString
 * @see php-src ext/dom/php_dom.c php_dom_get_element_by_id
 *
 * @group llvm
 * @group aot
 */
final class DomHtmlDocumentCfsGetElementByIdAotTest extends TestCase
{
    public function testLivingGetElementByIdRoutesToDedicatedCall(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'dom\\\\htmldocument::getelementbyid' === \$lc", $source);
        $this->assertStringContainsString('#35792', $source);
        $this->assertStringContainsString('new Call\\DomDocumentGetElementById()', $source);
        $gei = (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/JitDomGetElementById.php');
        $this->assertStringContainsString('isLivingHtmlCreateFromStringFold', $gei);
        $this->assertStringContainsString('lastCreateFromStringSource', $gei);
        $cfs = (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/JitDomHtmlDocumentCreateFromString.php');
        $this->assertStringContainsString('JitDomLoadHTMLUserScript::rememberCreateFromStringHtml', $cfs);
    }

    public function testParseHelperFindsCfsId(): void
    {
        $html = '<!DOCTYPE html><html><body><div id="p">hi</div></body></html>';
        $byId = \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::parseIdElementArgv($html, 'p');
        $this->assertNotNull($byId);
        $this->assertSame('div', $byId['tag']);
        $this->assertSame('p', $byId['id']);
        $this->assertSame('hi', $byId['text']);
        $this->assertNull(
            \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::parseIdElementArgv($html, 'nope')
        );
    }

    public function testVmCreateFromStringGetElementById(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living API needs PHP_COMPILER_PROFILE=8.4');
            }
            $src = dirname(__DIR__).'/repro/aot_dom_html_cfs_getelementbyid.php';
            $vm = $this->runVm($src);
            $this->assertSame("DIV\nhi\nnull\n", $vm);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    public function testAotCreateFromStringGetElementByIdMatchesVm(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living API needs PHP_COMPILER_PROFILE=8.4');
            }

            $src = dirname(__DIR__).'/repro/aot_dom_html_cfs_getelementbyid.php';
            $this->assertFileExists($src);

            $vm = $this->runVm($src);
            $this->assertSame("DIV\nhi\nnull\n", $vm);

            $bin = sys_get_temp_dir().'/phpc_35792_'.getmypid().'.bin';
            $compile = $this->runCompile($src, $bin);
            $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
            $aot = $this->runBin($bin);
            @unlink($bin);

            $this->assertSame(0, $aot['code'], "AOT run failed:\n".$aot['out']);
            $this->assertSame($vm, $aot['out']);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 php '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "VM failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    /** @return array{code:int,out:string} */
    private function runCompile(string $src, string $bin): array
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 php '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines)];
    }

    /** @return array{code:int,out:string} */
    private function runBin(string $bin): array
    {
        exec(escapeshellarg($bin).' 2>&1', $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines).([] === $lines ? '' : "\n")];
    }
}

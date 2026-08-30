<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT Dom\HTMLDocument::saveXml leftover of CFS / saveHtml.
 *
 * php-src: ext/dom/html_document.c, ext/dom/php_dom.c xmlDocDumpMemory
 *
 * @group llvm
 */
final class DomHtmlDocumentSaveXmlAotTest extends TestCase
{
    public function testAotHtmlDocumentSaveXmlMatchesVm(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living API needs PHP_COMPILER_PROFILE=8.4');
            }

            $src = dirname(__DIR__).'/repro/aot_dom_living_savexml.php';
            $this->assertFileExists($src);

            $vm = $this->runVm($src);
            $this->assertStringContainsString('<p id="x">hi</p>', $vm);
            $this->assertStringContainsString('<root><child>t</child></root>', $vm);
            $this->assertStringNotContainsString('<html/>', $vm);

            $bin = sys_get_temp_dir().'/phpc_html_savexml_'.getmypid().'.bin';
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
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 php '.escapeshellarg($root.'/bin/compile.php')
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

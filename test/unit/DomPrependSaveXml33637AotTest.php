<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ParentNode::prepend must not duplicate saveXML children (#33637).
 *
 * @group llvm
 * @group aot
 */
final class DomPrependSaveXml33637AotTest extends TestCase
{
    public function testPrependCreateElementSaveXmlMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_33637_dom_prepend_save_xml.php';
        $zend = $this->runPhp($src);
        $this->assertSame('<r><z/><a/></r>', $zend);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testPrependPathRebuildsInnerXmlNotConcat(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Builtin/DomNodeLiveMutationRuntime.php');
        $this->assertStringContainsString('#33637', $src);
        $this->assertStringContainsString(
            "if ('prepend' === \$kind && self::canUseObjectMutationBridge(\$extraArgs))",
            $src
        );
        // Prepend thin-AOT arm must rebuild like append — not concat onto INNER_XML.
        $armStart = strpos($src, "if ('prepend' === \$kind && self::canUseObjectMutationBridge(\$extraArgs))");
        $this->assertNotFalse($armStart);
        $armEnd = strpos($src, "return self::nullValuePtr(\$context);", $armStart);
        $this->assertNotFalse($armEnd);
        $arm = substr($src, $armStart, $armEnd - $armStart);
        $this->assertStringContainsString('rebuildUserScriptInnerXmlFromElementChildren', $arm);
        $this->assertStringContainsString('markTreeMutatedSinceLoad', $arm);
        $this->assertStringNotContainsString('syncUserScriptInnerXmlFromArgs(', $arm);
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
        $bin = sys_get_temp_dir().'/dom_prepend_33637_'.getmypid();
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}

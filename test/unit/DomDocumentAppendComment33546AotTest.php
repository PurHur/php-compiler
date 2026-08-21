<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Document appendChild(comment) must not install documentElement (#33546).
 *
 * @group llvm
 */
final class DomDocumentAppendComment33546AotTest extends TestCase
{
    public function testCommentThenElement(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33546_dom_append_comment_then_element_aot.php');
    }

    public function testCommentOnlyDocumentElementNull(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33546_dom_append_comment_only_aot.php');
    }

    public function testPlainElementAppendStillWorks(): void
    {
        $src = sys_get_temp_dir().'/dom_33546_el_'.getmypid().'.php';
        file_put_contents($src, "<?php\n\$d=new DOMDocument();\n\$e=\$d->createElement('r');\n\$d->appendChild(\$e);\necho \$d->documentElement->tagName,\"\\n\";\n");
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
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
        $bin = sys_get_temp_dir().'/dom_doc_ac_33546_'.getmypid().'_'.md5($src);
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

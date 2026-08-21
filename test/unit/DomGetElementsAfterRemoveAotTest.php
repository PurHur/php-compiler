<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName length after removeChild / insertBefore (#33679).
 *
 * @group llvm
 * @group aot
 */
final class DomGetElementsAfterRemoveAotTest extends TestCase
{
    public function testVmRemoveStar(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_33679_dom_getelements_after_remove_aot.php'
            );
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'issue_33679_dom_getelements_after_remove_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertSame("len=2\nr,a\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmInsertBeforeStar(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_33679_dom_getelements_insertbefore_aot.php'
            );
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'issue_33679_dom_getelements_insertbefore_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertSame("len=4\nr,a,b,c\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAotRemoveAndInsertBefore(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $cases = [
            'append_remove_star' => [
                'code' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$b = $d->createElement('b');
$d->documentElement->appendChild($b);
$d->documentElement->removeChild($b);
$list = $d->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
                'expect' => "len=2\nr,a\n",
            ],
            'insertbefore_star' => [
                'code' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$r = $d->documentElement;
$r->insertBefore($d->createElement('b'), $r->lastChild);
$list = $d->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
                'expect' => "len=4\nr,a,b,c\n",
            ],
            'query_then_remove' => [
                'code' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$list = $d->getElementsByTagName('*');
$d->documentElement->removeChild($d->documentElement->lastChild);
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
                'expect' => "len=2\nr,a\n",
            ],
            'append_regression_33659' => [
                'code' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->appendChild($d->createElement('b'));
$list = $d->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
                'expect' => "len=3\nr,a,b\n",
            ],
        ];
        foreach ($cases as $name => $case) {
            $src = sys_get_temp_dir().'/phpc_33679_'.$name.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_33679_'.$name.'_'.getmypid().'.bin';
            file_put_contents($src, $case['code']);
            try {
                $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
                    .escapeshellarg(PHP_BINARY).' '
                    .escapeshellarg($root.'/bin/compile.php')
                    .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
                exec($compile, $compileOut, $compileRc);
                $this->assertSame(0, $compileRc, $name." compile:\n".implode("\n", $compileOut));
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, $name.' run: '.implode("\n", $runOut));
                $this->assertSame($case['expect'], implode("\n", $runOut)."\n", $name);
            } finally {
                @unlink($src);
                @unlink($bin);
            }
        }
    }
}

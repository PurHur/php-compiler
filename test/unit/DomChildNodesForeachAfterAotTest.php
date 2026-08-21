<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach over childNodes after after/before/append/prepend (#33645).
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodesForeachAfterAotTest extends TestCase
{
    public function testVmAfterForeach(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_33645_dom_childnodes_foreach_after_aot.php'
            );
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'issue_33645_dom_childnodes_foreach_after_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertSame("a,b\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAotAfterBeforeAppendPrependForeach(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $cases = [
            'after' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->firstChild->after($d->createElement('b'));
$names = [];
foreach ($d->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
            'before' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->firstChild->before($d->createElement('z'));
$names = [];
foreach ($d->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
            'append' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->append($d->createElement('z'));
$names = [];
foreach ($d->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
            'prepend' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->prepend($d->createElement('z'));
$names = [];
foreach ($d->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
            'nomat' => <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$names = [];
foreach ($d->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) { $names[] = $n->nodeName; }
}
echo implode(',', $names), "\n";
PHP,
        ];
        $expected = [
            'after' => "a,b\n",
            'before' => "z,a\n",
            'append' => "a,z\n",
            'prepend' => "z,a\n",
            'nomat' => "a,b,c\n",
        ];
        foreach ($cases as $name => $code) {
            $src = sys_get_temp_dir().'/phpc_33645_'.$name.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_33645_'.$name.'_'.getmypid().'.bin';
            file_put_contents($src, $code);
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
                $this->assertSame($expected[$name], implode("\n", $runOut)."\n", $name);
            } finally {
                @unlink($src);
                @unlink($bin);
            }
        }
    }
}

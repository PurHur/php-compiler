<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IncludeHelper-style order: library `$callable()` lowered before entry closures exist (#36382).
 *
 * Without deferred RuntimeIndirectClosureCall materialize, RequestResponse-shaped invoke
 * aborts comparing `{closure}_N` targets snapshotted mid-graph.
 *
 * @group llvm
 */
final class Issue36382DeferredRicAcrossIncludeAotTest extends TestCase
{
    public function testCallableInvokeSeesEntryClosureDefinedAfterInclude(): void
    {
        $dir = sys_get_temp_dir().'/phpc_36382_ric_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $lib = $dir.'/lib.php';
        $main = $dir.'/main.php';
        $bin = $dir.'/out';
        file_put_contents($lib, <<<'PHP'
<?php
class Strat {
    public function __invoke(callable $callable, $a, $b, array $args) {
        return $callable($a, $b, $args);
    }
}
function make_other() {
    return function () {
        return 'other';
    };
}
PHP);
        file_put_contents($main, <<<'PHP'
<?php
require __DIR__ . '/lib.php';
$o = make_other();
$hello = function ($a, $b, $args) {
    return 'hello';
};
$s = new Strat();
echo $s($hello, 1, 2, []), "\n";
PHP);

        $compile = sprintf(
            'php -d memory_limit=2048M %s -o %s %s 2>&1',
            escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($main)
        );
        exec($compile, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);

        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        $this->assertSame(['hello'], $runOut);
    }
}

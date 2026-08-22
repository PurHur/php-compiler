<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT http_build_query on runtime hashtables (#33711).
 *
 * @group llvm
 * @group aot
 */
final class Issue33711HttpBuildQueryRuntimeAotTest extends TestCase
{
    public function testRuntimeArrayMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33711_http_build_query_runtime_aot.php');
    }

    public function testNestedAssocMatchesZend(): void
    {
        $src = sys_get_temp_dir().'/hbq_nest_33711_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php \$c=['x'=>1,'y'=>['z'=>2]]; echo http_build_query(\$c), PHP_EOL;\n"
        );
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
        $bin = sys_get_temp_dir().'/ao_33711_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}

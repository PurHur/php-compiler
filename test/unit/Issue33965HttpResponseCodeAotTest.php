<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: http_response_code after Type::initialize always-on drop (#33965).
 *
 * Zend CLI prints bool true as "1" on first set; AOT may also append a CGI
 * Status trailer (same on master — not introduced by this shrink).
 *
 * @group llvm
 * @group aot
 */
final class Issue33965HttpResponseCodeAotTest extends TestCase
{
    public function testHttpResponseCodeAotMatchesZendPrefix(): void
    {
        $src = __DIR__.'/../repro/issue_33965_http_response_code_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame("1\n201\n", $zend);
        $this->assertStringStartsWith($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).(count($out) > 0 ? "\n" : '');
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/issue_33965_hrc_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $out = [];
            $rc = 0;
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out).(count($out) > 0 ? "\n" : '');
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT Uri('/hello') must keep path under component parse_url ctor.
 *
 * @group aot
 */
final class Issue36382UriComponentConstructAotTest extends TestCase
{
    public function testUriHelloPathSurvivesAot(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_uri_component_construct.php';
        $this->assertFileExists($src);
        $bin = tempnam(sys_get_temp_dir(), 'uri_cc_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        $cmd = sprintf(
            'php -d memory_limit=1024M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        putenv('PHP_COMPILER_CACHE');
        unset($_ENV['PHP_COMPILER_CACHE']);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $ec, $joined);
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runLines, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame("path=/hello\npath2=/hello\nOK\n", implode("\n", $runLines)."\n");
    }
}

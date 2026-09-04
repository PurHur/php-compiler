<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\PregAotFastPath;
use PHPUnit\Framework\TestCase;

/**
 * #36382 — thin AOT PregAotFastPath matches Nyholm MessageTrait header patterns.
 *
 * php-src: ext/pcre/php_pcre.c; Nyholm MessageTrait validateAndTrimHeader
 *
 * @group aot
 */
final class Issue36382NyholmHeaderPregAotTest extends TestCase
{
    public function testFastPathHeaderNameAndValue(): void
    {
        $name = "@^[!#$%&'*+.^_`|~0-9A-Za-z-]+$@D";
        $this->assertSame(1, PregAotFastPath::matchCount($name, 'Content-Type', 0));
        $this->assertSame(0, PregAotFastPath::lastError());
        $this->assertSame(0, PregAotFastPath::matchCount($name, 'Bad Header', 0));
        $this->assertSame(0, PregAotFastPath::matchCount($name, '', 0));

        $value = "@^[ \t\x21-\x7E\x80-\xFF]*$@D";
        $this->assertSame(1, PregAotFastPath::matchCount($value, 'text/plain', 0));
        $this->assertSame(1, PregAotFastPath::matchCount($value, '', 0));
        $this->assertSame(0, PregAotFastPath::lastError());
    }

    public function testAotBinaryMatchesZend(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_trait_private_mutual.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'nyholm36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s --no-cache -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame('text/plain', trim(implode("\n", $runLines)));
    }
}

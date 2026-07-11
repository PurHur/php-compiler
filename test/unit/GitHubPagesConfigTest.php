<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GitHubPagesConfigTest extends TestCase
{
    public function testRootConfigScopesPagesBuild(): void
    {
        $root = dirname(__DIR__, 2);
        $script = escapeshellarg($root.'/script/check-github-pages-config.php');
        $out = [];
        $code = 0;
        exec("php {$script} 2>&1", $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('OK', implode("\n", $out));
    }

    public function testRootConfigExists(): void
    {
        $path = dirname(__DIR__, 2).'/_config.yml';
        $this->assertFileExists($path);
        $yaml = (string) file_get_contents($path);
        $this->assertStringContainsString('layouts_dir: docs/pages/_layouts', $yaml);
        $this->assertStringContainsString('- build', $yaml);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\SourceBundler;

/**
 * AOT bundle must not leave top-level return from config includes (issue #452, #485).
 */
final class SourceBundlerTest extends TestCase
{
    public function testReturnOnlyConfigIncludeBecomesAssignment(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/config.php',
                "<?php\ndeclare(strict_types=1);\n\nreturn ['app_name' => 'TestApp'];\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\n\$config = require __DIR__ . '/../config.php';\necho \$config['app_name'];\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/config.php') ?: $dir.'/config.php']
            );

            $this->assertStringNotContainsString('return [', $bundled);
            $this->assertStringContainsString("\$config = ['app_name' => 'TestApp'];", $bundled);
            $this->assertStringContainsString("echo \$config['app_name'];", $bundled);
        } finally {
            @unlink($dir.'/config.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }
}

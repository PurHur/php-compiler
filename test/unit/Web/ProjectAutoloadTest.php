<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PSR-4 autoload from phpc.json (issue #155). */
final class ProjectAutoloadTest extends TestCase
{
    public function testResolveClassPathMapsNamespacePrefixToFile(): void
    {
        $dir = sys_get_temp_dir().'/phpc_autoload_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        try {
            file_put_contents($dir.'/src/Router.php', '<?php namespace App; class Router {}');
            $map = ['App\\' => $dir.'/src'];
            $path = ProjectAutoload::resolveClassPath('App\\Router', $map);
            $this->assertNotNull($path);
            $this->assertStringEndsWith('/src/Router.php', $path);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testVmAutoloadLoadsClassOnDemand(): void
    {
        $dir = sys_get_temp_dir().'/phpc_autoload_vm_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/src/Greeter.php',
                <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class Greeter
{
    public function greet(string $name): string
    {
        return 'hi '.$name;
    }
}
PHP
            );
            file_put_contents(
                $dir.'/public/index.php',
                <<<'PHP'
<?php

declare(strict_types=1);

$g = new App\Greeter();
echo $g->greet('Dev');
PHP
            );
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'public' => 'public',
                    'autoload' => ['psr-4' => ['App\\' => 'src/']],
                ], JSON_THROW_ON_ERROR)
            );

            $runtime = new Runtime();
            ProjectBootstrap::prepare($runtime, $dir, json_decode(
                (string) file_get_contents($dir.'/phpc.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            ));
            $entry = $dir.'/public/index.php';
            $block = $runtime->parseAndCompile((string) file_get_contents($entry), $entry);
            ob_start();
            $runtime->run($block);
            $output = ob_get_clean();
            $this->assertSame('hi Dev', $output);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidatePsr4PathsOnDiskRejectsMissingBase(): void
    {
        $dir = sys_get_temp_dir().'/phpc_autoload_val_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            $errors = ProjectAutoload::validatePsr4PathsOnDisk($dir, [
                'psr-4' => ['App\\' => 'missing/'],
            ]);
            $this->assertNotSame([], $errors);
            $this->assertStringContainsString('autoload.psr-4 base directory not found', $errors[0]);
        } finally {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

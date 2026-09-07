<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NyholmStreamStringBodyPatch36382Test extends TestCase
{
    public function testPatchRewritesStringCreateAndIsIdempotent(): void
    {
        $dir = sys_get_temp_dir().'/phpc-stream-string-36382-'.getmypid();
        @mkdir($dir);
        $path = $dir.'/Stream.php';
        $src = <<<'PHP'
<?php

declare(strict_types=1);

namespace Nyholm\Psr7;

use Psr\Http\Message\StreamInterface;

class Stream implements StreamInterface
{
    use StreamTrait;

    public static function create($body = ''): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if (\is_string($body)) {
            if (200000 <= \strlen($body)) {
                $body = self::openZvalStream($body);
            } else {
                $resource = \fopen('php://memory', 'r+');
                \fwrite($resource, $body);
                \fseek($resource, 0);
                $body = $resource;
            }
        }

        if (!\is_resource($body)) {
            throw new \InvalidArgumentException('First argument to Stream::create() must be a string, resource or StreamInterface');
        }

        return new self($body);
    }

    private static function openZvalStream(string $body)
    {
        $resource = \fopen('php://temp', 'r+');
        \fwrite($resource, $body);
        \fseek($resource, 0);

        return $resource;
    }
}
PHP;
        file_put_contents($path, $src);
        $script = dirname(__DIR__, 2).'/script/composer/patch-nyholm-stream-string-body-36382.php';
        $once = shell_exec('php '.escapeshellarg($script).' '.escapeshellarg($path).' 2>&1');
        $this->assertStringContainsString('patched Stream.php string-body', (string) $once);
        $this->assertStringContainsString('wrote ', (string) $once);
        $patched = (string) file_get_contents($path);
        $this->assertStringContainsString('AOT (#36382): string body without php://memory fopen', $patched);
        $this->assertStringContainsString('return new AotStringStream36382($body);', $patched);
        $this->assertStringNotContainsString("fopen('php://memory'", $patched);
        $helper = $dir.'/AotStringStream36382.php';
        $this->assertFileExists($helper);
        $this->assertStringContainsString('class AotStringStream36382', (string) file_get_contents($helper));

        $twice = shell_exec('php '.escapeshellarg($script).' '.escapeshellarg($path).' 2>&1');
        $this->assertStringContainsString('already patched', (string) $twice);

        @unlink($path);
        @unlink($helper);
        @rmdir($dir);
    }
}

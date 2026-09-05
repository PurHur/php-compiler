<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NyholmStreamTraitPatch36382Test extends TestCase
{
    public function testPatchCollapsesVersionGatedTrait(): void
    {
        $dir = sys_get_temp_dir().'/phpc-stream-trait-36382-'.getmypid();
        @mkdir($dir);
        $path = $dir.'/StreamTrait.php';
        $src = <<<'PHP'
<?php

declare(strict_types=1);

namespace Nyholm\Psr7;

use Psr\Http\Message\StreamInterface;
use Symfony\Component\Debug\ErrorHandler as SymfonyLegacyErrorHandler;
use Symfony\Component\ErrorHandler\ErrorHandler as SymfonyErrorHandler;

if (\PHP_VERSION_ID >= 70400 || (new \ReflectionMethod(StreamInterface::class, '__toString'))->hasReturnType()) {
    /**
     * @internal
     */
    trait StreamTrait
    {
        public function __toString(): string
        {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        }
    }
} else {
    /**
     * @internal
     */
    trait StreamTrait
    {
        /**
         * @return string
         */
        public function __toString()
        {
            try {
                if ($this->isSeekable()) {
                    $this->seek(0);
                }

                return $this->getContents();
            } catch (\Throwable $e) {
                if (\is_array($errorHandler = \set_error_handler('var_dump'))) {
                    $errorHandler = $errorHandler[0] ?? null;
                }
                \restore_error_handler();

                if ($e instanceof \Error || $errorHandler instanceof SymfonyErrorHandler || $errorHandler instanceof SymfonyLegacyErrorHandler) {
                    return \trigger_error((string) $e, \E_USER_ERROR);
                }

                return '';
            }
        }
    }
}
PHP;
        file_put_contents($path, $src);
        $script = dirname(__DIR__, 2).'/script/composer/patch-nyholm-stream-trait-36382.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($path);
        exec($cmd.' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $patched = (string) file_get_contents($path);
        $this->assertStringContainsString('AOT (#36382): collapse PHP_VERSION_ID StreamTrait if/else', $patched);
        $this->assertStringNotContainsString('PHP_VERSION_ID >= 70400', $patched);
        $this->assertStringContainsString('public function __toString(): string', $patched);
        $this->assertStringNotContainsString('SymfonyLegacyErrorHandler', $patched);
        $this->assertStringNotContainsString('use Psr\\Http\\Message\\StreamInterface;', $patched);

        // Idempotent.
        exec($cmd.' 2>&1', $out2, $code2);
        $this->assertSame(0, $code2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));

        @unlink($path);
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @group imagecreatefromstring
 */
final class ImagecreatefromstringTest extends TestCase
{
    public function test_png_decode_and_round_trip(): void
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('host php-gd required for imagecreatefromstring (#22740)');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo (int) function_exists('imagecreatefromstring');
$im = imagecreatefromstring($png);
echo get_class($im);
ob_start();
imagepng($im);
echo strlen(ob_get_clean()) > 8 ? 'ok' : 'fail';
PHP;
        $block = $runtime->parseAndCompile($code, 'imagecreatefromstring_test.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1GdImageok', ob_get_clean());
    }
}

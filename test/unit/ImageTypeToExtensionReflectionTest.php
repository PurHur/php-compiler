<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** image_type_to_extension Reflection string|false (#28314, image.stub.php). */
final class ImageTypeToExtensionReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28314_image_type_to_extension_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28314.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "image_type_to_extension=string|false\nvalue0=false\nvalue2='.jpeg'\n",
            ob_get_clean()
        );
    }
}

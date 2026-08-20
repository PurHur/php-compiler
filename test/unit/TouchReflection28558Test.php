<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * touch() Reflection: ?int $mtime / $atime matching Zend file.stub.php (#28558).
 *
 * @see php-src ext/standard/file.stub.php
 */
final class TouchReflection28558Test extends TestCase
{
    public function testTouchReflectionNullableIntParamsViaVm(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28558_touch_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28558_touch_reflection.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "filename:string:default=-\n"
            ."mtime:?int:default=NULL\n"
            ."atime:?int:default=NULL\n",
            ob_get_clean()
        );
    }

    public function testTouchNullMtimeAtimeRuntimeViaVm(): void
    {
        $code = <<<'PHP'
<?php
$path = sys_get_temp_dir().'/phpc_touch_28558_'.getmypid().'.txt';
@unlink($path);
$ok = touch($path, null, null);
echo $ok && is_file($path) ? "ok\n" : "bad\n";
@unlink($path);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28558_touch_null.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}

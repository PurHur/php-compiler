<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\Superglobals;

/**
 * Issue #1058: ?? on $_SERVER['KEY'] must not clobber other $_SERVER entries.
 */
final class SuperglobalCoalesceVmTest extends TestCase
{
    public function testServerCoalescePreservesRequestMethodFromCgiEnv(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('PATH_INFO=/contact');
        putenv('SCRIPT_NAME=/index.php');
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext);
        $block = $runtime->parseAndCompile(
            '<?php $path = $_SERVER["PATH_INFO"] ?? ""; $method = $_SERVER["REQUEST_METHOD"] ?? "GET"; echo $method, " ", $path;',
            'test.php'
        );
        VM\OutputBuffer::reset();
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() not used
        }
        $out = (string) ob_get_clean();
        $this->assertSame('POST /contact', trim($out));
    }
}

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

    /**
     * Chained superglobal ?? must compile without makeIssetOpCode(null container) (#1492 bootstrap).
     */
    public function testChainedSuperglobalCoalesceCompiles(): void
    {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext);
        $block = $runtime->parseAndCompile(
            '<?php $v = $_SERVER["PHP_COMPILER_DEBUG_LAST_PHASE"] ?? $_ENV["PHP_COMPILER_DEBUG_LAST_PHASE"] ?? getenv("PHP_COMPILER_DEBUG_LAST_PHASE"); echo $v === false ? "0" : "1";',
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
        $this->assertSame('0', trim($out));
    }
}

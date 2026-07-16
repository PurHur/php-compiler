<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\BuiltinClasses as CurlBuiltinClasses;
use PHPCompiler\ext\curl\VmCurlEasy;
use PHPCompiler\ext\curl\VmCurlShare;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #11791 */
final class CurlHandleDirectConstructTest extends TestCase
{
    public function testDirectConstructThrowsErrorWhenHandleClassesRegistered(): void
    {
        $runtime = new Runtime();
        ModuleRegistry::register('curl');
        CurlBuiltinClasses::register($runtime->vmContext);
        // Force-register handle classes (withheld by policy until extension_loaded; #19728).
        VmCurlEasy::registerClass($runtime->vmContext);
        VmCurlShare::registerClass($runtime->vmContext);
        if (!isset($runtime->vmContext->classes['curlmultihandle'])) {
            $runtime->vmContext->classes['curlmultihandle'] = new \PHPCompiler\VM\ClassEntry('CurlMultiHandle');
        }

        $code = <<<'PHP'
<?php
foreach ([
    'CurlHandle' => 'curl_init()',
    'CurlMultiHandle' => 'curl_multi_init()',
    'CurlShareHandle' => 'curl_share_init()',
] as $class => $hint) {
    try {
        new $class();
        echo "fail: new {$class}() succeeded\n";
    } catch (Error $e) {
        echo $class, ':', $e->getMessage(), "\n";
    }
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_handle_direct_construct.php'));
        self::assertSame(
            "CurlHandle:Cannot directly construct CurlHandle, use curl_init() instead\n"
            ."CurlMultiHandle:Cannot directly construct CurlMultiHandle, use curl_multi_init() instead\n"
            ."CurlShareHandle:Cannot directly construct CurlShareHandle, use curl_share_init() instead\n",
            ob_get_clean()
        );
    }
}

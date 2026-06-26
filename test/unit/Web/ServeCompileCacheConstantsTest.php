<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\ext\standard\VmStatCache;
use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ProjectBootstrap;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\ServeCompileCache;
use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/** ServeCompileCache must not share mutable constant cells across VM runs (#12040). */
final class ServeCompileCacheConstantsTest extends TestCase
{
    protected function tearDown(): void
    {
        ServeCompileCache::reset();
        parent::tearDown();
    }

    public function testCachedEntryScriptKeepsHeaderLiteralAcrossRuns(): void
    {
        $script = realpath(__DIR__.'/../../../examples/002-StaticWeb/example.php');
        $this->assertNotFalse($script);
        $docroot = dirname($script);

        ServeCompileCache::enable();
        $runtime = new Runtime();
        [$projectDir, $manifest] = ProjectBootstrap::resolveFromScript($script);
        ProjectBootstrap::prepare($runtime, $projectDir, $manifest);
        $block = ServeCompileCache::getFile($runtime, $script);
        $this->assertNotNull($block);

        foreach (['/example.php', '/example.php'] as $requestUri) {
            ResponseContext::reset();
            ResponseContext::enableHeaderQueue();
            VmSession::reset();
            VmStatCache::reset();
            OutputBuffer::reset();
            ShutdownQueue::reset();
            DevServer::clearHttpServerKeys();

            putenv('REQUEST_METHOD=GET');
            putenv('QUERY_STRING=');
            putenv('REQUEST_BODY=');
            putenv('SCRIPT_NAME=/example.php');
            putenv('SCRIPT_FILENAME='.$script);
            putenv('REQUEST_URI='.$requestUri);
            putenv('DOCUMENT_ROOT='.$docroot);
            putenv('SERVER_PROTOCOL=HTTP/1.1');
            putenv('PATH_INFO');
            Superglobals::applyHttpHeaders(['host' => 'localhost', 'connection' => 'close']);

            $run = new Runtime();
            Superglobals::populateFromEnvironment($run->vmContext, '', '', $script);
            ProjectBootstrap::prepare($run, $projectDir, $manifest);
            $cached = ServeCompileCache::getFile($run, $script);
            $this->assertSame($block, $cached);
            $run->run($cached);

            $this->assertSame(
                ['Content-Type: text/html; charset=UTF-8'],
                ResponseContext::listHeaders(),
                'request '.$requestUri
            );
        }
    }
}

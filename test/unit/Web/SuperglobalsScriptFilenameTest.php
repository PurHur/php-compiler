<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #302: $_SERVER['SCRIPT_FILENAME'] for CGI routing and includes.
 */
final class SuperglobalsScriptFilenameTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SCRIPT_FILENAME');
        putenv('DOCUMENT_ROOT');
        putenv('SCRIPT_NAME');
    }

    public function testResolveScriptFilenameFromEnvironment(): void
    {
        putenv('SCRIPT_FILENAME=/var/www/html/index.php');
        $this->assertSame('/var/www/html/index.php', Superglobals::resolveScriptFilename());
    }

    public function testResolveScriptFilenameJoinsDocumentRootAndScriptName(): void
    {
        putenv('SCRIPT_FILENAME');
        putenv('DOCUMENT_ROOT=/var/www/html');
        $this->assertSame(
            '/var/www/html/app/index.php',
            Superglobals::resolveScriptFilename('/app/index.php')
        );
    }

    public function testPopulateSetsScriptFilenameOnServerSuperglobal(): void
    {
        putenv('SCRIPT_FILENAME=/var/www/html/index.php');
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', '');

        $server = $runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $var = $server->find('SCRIPT_FILENAME');
        $this->assertNotNull($var);
        $this->assertSame('/var/www/html/index.php', $var->resolveIndirect()->toString());
    }
}

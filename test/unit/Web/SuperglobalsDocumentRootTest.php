<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #296: $_SERVER['DOCUMENT_ROOT'] from CGI DOCUMENT_ROOT env.
 */
final class SuperglobalsDocumentRootTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        putenv('DOCUMENT_ROOT');
        unset($_SERVER['DOCUMENT_ROOT']);
    }

    public function testDocumentRootFromEnvironment(): void
    {
        $root = realpath(sys_get_temp_dir());
        $this->assertNotFalse($root);
        putenv('DOCUMENT_ROOT='.$root);

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame($root, $this->readServer($server, 'DOCUMENT_ROOT'));
    }

    public function testDocumentRootOmittedWhenUnset(): void
    {
        putenv('DOCUMENT_ROOT');

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('', $this->readServer($server, 'DOCUMENT_ROOT'));
    }

    private function readServer(\PHPCompiler\VM\HashTable $server, string $key): string
    {
        $var = $server->find($key);
        if (null === $var) {
            return '';
        }
        $resolved = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_STRING !== $resolved->type) {
            return '';
        }

        return $resolved->toString();
    }
}

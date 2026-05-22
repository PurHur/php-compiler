<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPUnit\Framework\TestCase;

final class SuperglobalsExportCgiTest extends TestCase
{
    public function testExportSetsQueryAndPostEnv(): void
    {
        $previousQuery = getenv('QUERY_STRING');
        $previousBody = getenv('REQUEST_BODY');
        $previousMethod = getenv('REQUEST_METHOD');

        Superglobals::exportCgiEnvironment('name=Dev', 'field=1', '/tmp/index.php');

        $this->assertSame('name=Dev', getenv('QUERY_STRING'));
        $this->assertSame('field=1', getenv('REQUEST_BODY'));
        $this->assertSame('POST', getenv('REQUEST_METHOD'));
        $this->assertSame('/tmp/index.php', getenv('SCRIPT_FILENAME'));

        if (false !== $previousQuery) {
            putenv('QUERY_STRING='.$previousQuery);
        } else {
            putenv('QUERY_STRING');
        }
        if (false !== $previousBody) {
            putenv('REQUEST_BODY='.$previousBody);
        } else {
            putenv('REQUEST_BODY');
        }
        if (false !== $previousMethod) {
            putenv('REQUEST_METHOD='.$previousMethod);
        } else {
            putenv('REQUEST_METHOD');
        }
        putenv('SCRIPT_FILENAME');
    }
}

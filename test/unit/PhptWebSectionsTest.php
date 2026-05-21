<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../PhptWebSections.php';

final class PhptWebSectionsTest extends TestCase
{
    public function testGetSectionMapsToQueryString(): void
    {
        $env = [];
        PhptWebSections::applyToEnv($env, ['GET' => "name=World&page=home\n"]);

        $this->assertSame('name=World&page=home', $env['QUERY_STRING']);
    }

    public function testPostSectionMapsToRequestBodyAndMethod(): void
    {
        $env = [];
        PhptWebSections::applyToEnv($env, ['POST' => "name=Ada&role=dev\n"]);

        $this->assertSame('name=Ada&role=dev', $env['REQUEST_BODY']);
        $this->assertSame('POST', $env['REQUEST_METHOD']);
    }

    public function testCookieSectionMapsToHttpCookie(): void
    {
        $env = [];
        PhptWebSections::applyToEnv($env, ['COOKIE' => "session=abc123; theme=dark\n"]);

        $this->assertSame('session=abc123; theme=dark', $env['HTTP_COOKIE']);
    }

    public function testWebSectionsOverrideEnv(): void
    {
        $env = ['QUERY_STRING' => 'old=1', 'REQUEST_BODY' => 'old=post'];
        PhptWebSections::applyToEnv($env, [
            'GET' => 'new=2',
            'POST' => 'new=post',
        ]);

        $this->assertSame('new=2', $env['QUERY_STRING']);
        $this->assertSame('new=post', $env['REQUEST_BODY']);
    }

    public function testCompileArgvFlags(): void
    {
        $flags = PhptWebSections::compileArgvFlags([
            'GET' => 'a=1',
            'POST' => 'b=2',
        ]);

        $this->assertSame(['-q', 'a=1', '-p', 'b=2'], $flags);
    }
}

<?php

declare(strict_types=1);

/**
 * #36382 — instance-method `new` with optional trailing ctor params (Nyholm shape).
 *
 * Compile must not raise argument-1 string/TYPE_OBJECT (duplicate `$this` prepend).
 * Runtime of this optional-param shape inside methods is a separate follow-up; the
 * no-optional sibling repro is used for end-to-end AOT execute.
 */

final class Request36382
{
    private string $method;

    public function __construct(
        string $method,
        $uri,
        array $headers = [],
        $body = null,
        string $version = '1.1'
    ) {
        $this->method = $method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
}

final class Psr17Factory36382
{
    public function createRequest(string $method, $uri): Request36382
    {
        return new Request36382($method, $uri);
    }
}

$f = new Psr17Factory36382();
$req = $f->createRequest('GET', 'x');
echo $req->getMethod(), "\n";

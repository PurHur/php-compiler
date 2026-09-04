<?php

declare(strict_types=1);

/**
 * #36382 — end-to-end AOT execute for instance-method `new` without optional params.
 */

final class Request36382Run
{
    private string $method;

    public function __construct(string $method, $uri)
    {
        $this->method = $method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
}

final class Psr17Factory36382Run
{
    public function createRequest(string $method, $uri): Request36382Run
    {
        return new Request36382Run($method, $uri);
    }
}

$f = new Psr17Factory36382Run();
echo $f->createRequest('GET', 'x')->getMethod(), "\n";

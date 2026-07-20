<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

/** Per-Manager URI storage (#6575). */
final class ManagerState
{
    public string $uri = 'mongodb://127.0.0.1/';
    /** @var array<string, mixed>|null */
    public ?array $uriOptions = null;
    /** @var array<string, mixed>|null */
    public ?array $driverOptions = null;
}

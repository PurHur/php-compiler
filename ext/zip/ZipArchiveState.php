<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * Mutable ZipArchive session state keyed by object id (issue #6414).
 */
final class ZipArchiveState
{
    public int $status = ZipArchiveConstants::ER_OK;

    public string $filename = '';

    public bool $open = false;

    public bool $dirty = false;

    /** @var list<array{name: string, data: string, crc: int, size: int}> */
    public array $entries = [];
}

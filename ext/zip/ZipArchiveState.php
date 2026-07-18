<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\VM\Variable;

/**
 * Mutable ZipArchive session state keyed by object id (issue #6414).
 */
final class ZipArchiveState
{
    public int $status = ZipArchiveConstants::ER_OK;

    public string $filename = '';

    public bool $open = false;

    public bool $dirty = false;

    /** Default archive password for setEncryptionName / decrypt (#19873). */
    public string $password = '';

    /**
     * Progress callback for registerProgressCallback (#20378).
     * Honest subset: invoked at end of mutating ops with state=1.0 (no libzip progress ticks).
     */
    public ?Variable $progressCallback = null;

    public float $progressRate = 0.0;

    /** Cancel callback for registerCancelCallback (#20378) — non-zero aborts close write. */
    public ?Variable $cancelCallback = null;

    /**
     * @var list<array{
     *     name: string,
     *     data: string,
     *     crc: int,
     *     size: int,
     *     mtime?: int,
     *     comp_method?: int,
     *     opsys?: int,
     *     external_attr?: int,
     *     encryption_method?: int,
     *     encryption_password?: string
     * }>
     */
    public array $entries = [];
}

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

    /**
     * System errno companion to $status (libzip zip_error_code_system).
     * Pure-PHP ZipEngine has no OS errno → stays 0 unless a mapped failure sets it (#20584).
     */
    public int $statusSys = 0;

    /**
     * Index of last successfully added entry (file or directory); -1 before any add (#20584).
     * php-src ze_zip_object::last_id.
     */
    public int $lastId = -1;

    public string $filename = '';

    public bool $open = false;

    public bool $dirty = false;

    /** Archive AFL_RDONLY / setReadOnly (#20412). */
    public bool $readOnly = false;

    /**
     * Current archive-flag bitmask (libzip zip_set/get_archive_flag; #21831).
     * Bits: AFL_RDONLY / AFL_IS_TORRENTZIP / AFL_WANT_TORRENTZIP / AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE.
     */
    public int $archiveFlags = 0;

    /** Archive flags at open — restored view for getArchiveFlag(..., FL_UNCHANGED) (#21831). */
    public int $openSnapshotArchiveFlags = 0;

    /** Default archive password for setEncryptionName / decrypt (#19873). */
    public string $password = '';

    /** EOCD archive comment — php-src zip_set_archive_comment (#20386). */
    public string $archiveComment = '';

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
     *     encryption_password?: string,
     *     comment?: string,
     *     orig_index?: int|null
     * }>
     */
    public array $entries = [];

    /**
     * Pristine entry snapshot taken on open for unchange* (#20387).
     *
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
     *     encryption_password?: string,
     *     comment?: string,
     *     orig_index?: int|null
     * }>
     */
    public array $openSnapshot = [];

    /** Archive comment at open — restored by unchangeArchive / unchangeAll (#20387). */
    public string $openSnapshotComment = '';
}

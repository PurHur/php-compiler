<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/** Internal ZipArchive property names for JIT/AOT (#35424). */
final class ZipArchiveJitSupport
{
    public const CLASS_NAME = 'ZipArchive';

    /** Internal handle into {@see ZipArchiveJitHelper} state for thin AOT (#35424). */
    public const PROP_ID = '__zipId';
}

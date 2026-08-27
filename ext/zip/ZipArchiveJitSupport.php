<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/** ZipArchive JIT/AOT property names (#35424). */
final class ZipArchiveJitSupport
{
    public const CLASS_NAME = 'ZipArchive';

    /** Handle into filesystem session ({@see ZipArchiveJitHelper}). */
    public const PROP_ID = '__zipId';
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Hidden DeflateContext / InflateContext slots for thin AOT incremental zlib (#35885 leftover of #4656).
 *
 * Peer HashContext {@see \PHPCompiler\ext\hash\HashContextJitSupport} (#3357).
 */
final class ZlibIncrementalJitSupport
{
    public const DEFLATE_CLASS = 'DeflateContext';

    public const INFLATE_CLASS = 'InflateContext';

    /** zlib encoding (ZLIB_ENCODING_*). */
    public const PROP_ENC = '__zEnc';

    /** compression level (-1..9). */
    public const PROP_LEVEL = '__zLevel';

    /** buffered payload until a non-NO_FLUSH add (VmZlibContext fallback). */
    public const PROP_BUF = '__zBuf';

    /** inflate_get_status mirror (0 / ZLIB_STREAM_END). */
    public const PROP_STATUS = '__zStatus';

    /** inflate_get_read_len mirror (bytes fed). */
    public const PROP_READ_LEN = '__zReadLen';
}

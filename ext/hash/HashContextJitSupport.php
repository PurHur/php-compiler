<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

/** Internal HashContext property names for JIT/AOT (#3357). */
final class HashContextJitSupport
{
    public const CLASS_NAME = 'HashContext';

    /** Internal handle into {@see HashContextJitHelper} state for JIT/AOT (#3357). */
    public const PROP_ID = '__hcId';

    /** Mirrored algorithm name for standalone AOT final (nested helper string returns crash #3357). */
    public const PROP_ALGO = '__hcAlgo';

    /** Mirrored update buffer for standalone AOT final (#3357). */
    public const PROP_BUF = '__hcBuf';
}

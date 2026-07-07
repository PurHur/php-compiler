<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

/** Internal HashContext property names for JIT/AOT (#3357). */
final class HashContextJitSupport
{
    public const CLASS_NAME = 'HashContext';

    public const PROP_ALGO = '__hcAlgo';

    public const PROP_DATA = '__hcData';

    public const PROP_LIVE = '__hcLive';
}

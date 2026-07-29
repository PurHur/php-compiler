<?php

declare(strict_types=1);

/**
 * AOT probe for #24788 — named decbin/dechex/decoct must compile (not Unknown named parameter).
 * bindec/hexdec/octdec AOT remains blocked by pre-existing MathBaseConvertRuntime iCmp
 * (widths 64 vs 32); covered on VM/JIT instead.
 */
echo 'decbin=', decbin(num: 10), "\n";
echo 'dechex=', dechex(num: 255), "\n";
echo 'decoct=', decoct(num: 15), "\n";

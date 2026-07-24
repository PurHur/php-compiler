<?php
/**
 * #22787 — PROFILE=8.5 AOT: IMAGETYPE_HEIF value + COUNT.
 * Run only under PHP_COMPILER_PROFILE=8.5 (constant must be registered for ConstFetch).
 */
echo 'HEIF=', IMAGETYPE_HEIF, "\n";
echo 'COUNT=', IMAGETYPE_COUNT, "\n";
echo 'AVIF=', IMAGETYPE_AVIF, "\n";

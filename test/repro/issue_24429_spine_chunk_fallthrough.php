<?php
/**
 * Minimal spine-chunk fallthrough probe (#24429).
 *
 * With PHP_COMPILER_SPINE_CHUNK=1, an unresolved method on an untyped/`object`
 * receiver must lower to ExternalMethod rather than abort with
 * "Call to undefined method object::…". That is what blocked every spine
 * chunk build from reaching PHP_COMPILER_REPORT_EXTERNAL_STUBS=1.
 *
 * Run (expect exit 0, and a stub report when REPORT is set):
 *   PHP_COMPILER_SPINE_CHUNK=1 PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 \
 *     php bin/compile.php -l test/repro/issue_24429_spine_chunk_fallthrough.php
 *
 * Without SPINE_CHUNK the same program must still abort (undefined method).
 */
function consume(object $o): int
{
    // No class in this unit defines missingElsewhere — mirrors lib/VM chunk
    // failing on object::currentexecutingframe() before any stub is recorded.
    return $o->missingElsewhere();
}

echo consume(new stdClass());

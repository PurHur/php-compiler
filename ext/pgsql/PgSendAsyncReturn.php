<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * Shared true / 0 / false return for pg_send_* (#20681).
 *
 * Static helper (not a trait) so Zend/AOT full-spine emit can compile this unit —
 * in-file and trait-only units both failed under spine emit ("Could not find trait").
 */
final class PgSendAsyncReturn
{
    public static function assign(\PHPCompiler\VM\Variable $returnVar, bool|int $out): void
    {
        if (true === $out) {
            $returnVar->bool(true);

            return;
        }
        if (false === $out) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->int($out);
    }
}

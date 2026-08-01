<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * Shared true / 0 / false return for pg_send_* (#20681).
 *
 * Own unit so Zend/AOT spine compile can resolve the trait before
 * {@see pg_async_builtins.php} classes that `use` it (in-file trait
 * discovery fails under full-spine emit).
 */
trait PgSendAsyncReturn
{
    private function assignSendReturn(\PHPCompiler\VM\Variable $returnVar, bool|int $out): void
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

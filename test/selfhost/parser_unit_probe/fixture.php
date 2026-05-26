<?php

declare(strict_types=1);

/**
 * Tiny CFG fixture: class + method + if (issue #2409).
 * Parsed by parser_unit_probe_parse_smoke() under Zend; not executed in native bundle.
 */

class ParserUnitProbeFixture
{
    public function run(bool $flag): int
    {
        if ($flag) {
            return 1;
        }

        return 0;
    }
}

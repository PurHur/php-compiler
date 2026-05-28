<?php

declare(strict_types=1);

use PHPCompiler\AOT\LinkerProcessPolyfill;

/** Bootstrap AOT lint fixture for LinkerProcessPolyfill::run lowering (#2779). */
function bootstrap_linker_process_polyfill_smoke(): int
{
    $captured = LinkerProcessPolyfill::run('echo linker-process-polyfill-smoke');
    if (!is_array($captured)) {
        return 1;
    }
    if (0 !== (int) ($captured['code'] ?? 1)) {
        return 2;
    }
    $stdout = $captured['stdout'] ?? '';
    if (!is_string($stdout) || !str_contains($stdout, 'linker-process-polyfill-smoke')) {
        return 3;
    }

    return 0;
}

exit(bootstrap_linker_process_polyfill_smoke());


<?php

declare(strict_types=1);

$g = (function (): Generator {
    yield 1;
})();
$g->next();
$r = $g->send(99);
echo 'send=', var_export($r, true), "\n";
echo 'valid_inline=', var_export($g->valid(), true), "\n";
$v = $g->valid();
echo 'valid_stored=', var_export($v, true), "\n";

<?php
$g = (function (): Generator {
    yield 1;
})();
$g->next();
echo 'valid=', var_export($g->valid(), true), "\n";
$result = $g->send(99);
echo 'send=', var_export($result, true), "\n";
echo 'valid_after=', var_export($g->valid(), true), "\n";

<?php
function two($a, $b) {
    echo 'a='; var_dump($a);
    echo 'b='; var_dump($b);
}
function val(): int { return 7; }
two(1 ? val() : 0, true);
echo 've=', var_export(1 ? 7 : 0, true), PHP_EOL;

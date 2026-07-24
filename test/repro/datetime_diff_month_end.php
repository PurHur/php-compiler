<?php
declare(strict_types=1);
// #22849 — DateTime::diff month-end calendar normalize (no negative d).
foreach ([['2020-01-31', '2020-03-01'], ['2021-01-31', '2021-03-01'], ['2020-01-01', '2020-02-01'], ['2020-01-15', '2020-03-15']] as [$a, $b]) {
    $d = (new DateTime($a))->diff(new DateTime($b));
    echo "$a->$b m={$d->m} d={$d->d} days={$d->days}\n";
}

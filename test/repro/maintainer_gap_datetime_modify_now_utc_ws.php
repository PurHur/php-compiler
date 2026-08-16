<?php
/** Maintainer gap: DateTime::modify now/UTC/whitespace (#31603). */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'W:', $str, "\n";
    return true;
});
foreach (['now', 'UTC', ' ', "\t", 'tomorrow'] as $m) {
    $d = new DateTime('2020-01-01 12:00:00');
    $r = $d->modify($m);
    echo json_encode($m), ' ret=', false === $r ? 'false' : 'obj',
        ' fmt=', $d->format('Y-m-d H:i:s'), "\n";
}

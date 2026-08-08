<?php

/**
 * Repro for #28982 — mb_convert_encoding() Quoted-Printable / qprint.
 *
 * php bin/vm.php test/repro/issue_28982_mb_convert_encoding_quoted_printable.php
 */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (str_contains($m, 'Handling QPrint via mbstring is deprecated')) {
        ++$deps;
    }

    return true;
});

foreach (['Quoted-Printable', 'qprint', 'QPrint', 'quoted-printable'] as $enc) {
    echo $enc, '=', mb_convert_encoding('A=B', $enc), "\n";
}
echo 'decode=', mb_convert_encoding('A=3DB', 'UTF-8', 'Quoted-Printable'), "\n";
echo 'listed=', in_array('Quoted-Printable', mb_list_encodings(), true) ? 'yes' : 'no', "\n";
echo 'deprecated=', $deps > 0 ? 'yes' : 'no', "\n";

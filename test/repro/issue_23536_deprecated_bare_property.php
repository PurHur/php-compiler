<?php

declare(strict_types=1);

/**
 * Repro #23536 — bare #[\Deprecated] on properties must emit E_USER_DEPRECATED
 * under PHP_COMPILER_PROFILE=8.4 (message form already green).
 */
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');

class C
{
    #[\Deprecated]
    public int $p = 2;
    #[\Deprecated('msg')]
    public int $q = 3;
}

$c = new C();
echo 'p=', $c->p, "\n";
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";

echo 'q=', $c->q, "\n";
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";

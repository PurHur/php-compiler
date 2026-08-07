<?php
// Repro #28464 — session lifecycle Reflection returns (session.stub.php)
foreach ([
    'session_write_close',
    'session_commit',
    'session_abort',
    'session_reset',
    'session_unset',
    'session_register_shutdown',
] as $f) {
    $r = new ReflectionFunction($f);
    $t = $r->getReturnType();
    echo $f, ' => ', $t ? (string) $t : '(none)', "\n";
}

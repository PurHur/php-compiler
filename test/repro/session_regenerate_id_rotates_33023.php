<?php
/**
 * AOT: session_regenerate_id() must rotate session_id() (#33023).
 * php-src: ext/session/session.c PHP_FUNCTION(session_regenerate_id)
 */
session_start();
$old = session_id();
$ok = session_regenerate_id(false);
$new = session_id();
echo 'ret=', $ok ? '1' : '0', "\n";
echo 'changed=', ($old !== $new) ? 'yes' : 'no', "\n";
echo 'lena=', strlen($old), "\n";
echo 'lenb=', strlen($new), "\n";

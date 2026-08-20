<?php
/**
 * AOT: session_create_id() must return distinct ids (#33023).
 * php-src: ext/session/session.c php_session_create_id / bin_to_readable
 */
$a = session_create_id();
$b = session_create_id();
echo 'lena=', strlen($a), "\n";
echo 'lenb=', strlen($b), "\n";
echo 'uniq=', ($a !== $b) ? 'yes' : 'no', "\n";

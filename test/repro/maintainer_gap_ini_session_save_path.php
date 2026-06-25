<?php

declare(strict_types=1);

$default = ini_get('session.save_path');
echo 'ini_session_save_path=', json_encode($default), "\n";

$old = ini_set('session.save_path', '/tmp/phpc-session-test');
echo 'ini_set_roundtrip=', json_encode(ini_get('session.save_path')), "\n";
if (false !== $old) {
    ini_set('session.save_path', $old);
}

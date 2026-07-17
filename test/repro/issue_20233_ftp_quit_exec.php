<?php
foreach (['ftp_quit', 'ftp_exec', 'ftp_close'] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

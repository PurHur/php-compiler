<?php
foreach (['ftp_rename', 'ftp_rmdir'] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

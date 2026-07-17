<?php
foreach (['ftp_pwd', 'ftp_cdup'] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

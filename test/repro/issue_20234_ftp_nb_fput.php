<?php
foreach (['ftp_nb_fput', 'ftp_nb_fget', 'ftp_fput'] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

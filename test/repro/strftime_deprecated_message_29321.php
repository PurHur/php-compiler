<?php
// #29321 — strftime/gmstrftime E_DEPRECATED must include since 8.1 + IntlDateFormatter hint.
error_reporting(E_ALL);
ini_set('display_errors', '0');
@strftime('%Y');
echo error_get_last()['message'] ?? '', "\n";
@gmstrftime('%Y');
echo error_get_last()['message'] ?? '', "\n";

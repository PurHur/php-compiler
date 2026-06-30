<?php
declare(strict_types=1);

define('JSON_INVALID_UTF8_IGNORE', 1048576);
define('JSON_INVALID_UTF8_SUBSTITUTE', 2097152);

foreach ([JSON_INVALID_UTF8_SUBSTITUTE, JSON_INVALID_UTF8_IGNORE] as $flag) {
    json_decode("\xFF", flags: $flag);
    $label = JSON_INVALID_UTF8_SUBSTITUTE === $flag ? 'substitute' : 'ignore';
    echo $label, ':', json_last_error(), ':', json_last_error_msg(), "\n";
}

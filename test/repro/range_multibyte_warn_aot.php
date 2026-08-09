<?php
/** #29203 AOT probe — multibyte range() warnings under PROFILE=8.4. */
error_reporting(E_ALL);
error_clear_last();
$r = @range('あ', 'う');
$last = error_get_last();
echo 'count=', count($r), "\n";
echo 'ord=', ord($r[0]), "\n";
echo 'type=', ($last['type'] ?? 'null'), "\n";
echo 'msg=', ($last['message'] ?? 'null'), "\n";

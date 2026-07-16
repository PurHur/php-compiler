<?php
declare(strict_types=1);

echo 'idn_to_ascii=', function_exists('idn_to_ascii') ? 'yes' : 'no', PHP_EOL;
echo 'idn_to_utf8=', function_exists('idn_to_utf8') ? 'yes' : 'no', PHP_EOL;
if (function_exists('idn_to_ascii')) {
    echo idn_to_ascii('例え.jp'), PHP_EOL;
    $back = idn_to_utf8(idn_to_ascii('例え.jp'));
    echo $back, PHP_EOL;
}

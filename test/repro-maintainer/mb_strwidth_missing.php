<?php
declare(strict_types=1);

foreach (['mb_strwidth', 'mb_strimwidth'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'NO', "\n";
}
echo mb_strwidth("あa", 'UTF-8'), "\n";
echo mb_strimwidth("あいう", 0, 4, '..', 'UTF-8'), "\n";

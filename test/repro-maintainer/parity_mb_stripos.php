<?php
declare(strict_types=1);

foreach (['mb_stripos', 'mb_strrpos', 'mb_strrichr'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo mb_stripos('Hello World', 'world', 0, 'UTF-8'), "\n";
echo mb_strrpos('Hello World', 'o', 0, 'UTF-8'), "\n";
echo mb_strrichr('Hello World', 'WORLD'), "\n";

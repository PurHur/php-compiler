<?php

declare(strict_types=1);

echo 'DECIMAL_POINT defined: '.(defined('DECIMAL_POINT') ? 'yes='.DECIMAL_POINT : 'no')."\n";
echo 'GROUPING defined: '.(defined('GROUPING') ? 'yes='.GROUPING : 'no')."\n";
echo 'THOUSANDS_SEP defined: '.(defined('THOUSANDS_SEP') ? 'yes='.THOUSANDS_SEP : 'no')."\n";

if (defined('DECIMAL_POINT')) {
    $item = nl_langinfo(DECIMAL_POINT);
    echo 'nl_langinfo(DECIMAL_POINT): '.var_export($item, true)."\n";
}

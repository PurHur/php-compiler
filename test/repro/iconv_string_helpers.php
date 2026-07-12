<?php

declare(strict_types=1);

$iso = "\xE9\xE8\xE7";
echo iconv_strlen($iso, 'ISO-8859-1'), "\n";
echo bin2hex(iconv_substr($iso, 1, 2, 'ISO-8859-1')), "\n";
echo var_export(iconv_strpos($iso, "\xE8", 1, 'ISO-8859-1')), "\n";
echo var_export(iconv_strrpos($iso, "\xE9", 'ISO-8859-1')), "\n";

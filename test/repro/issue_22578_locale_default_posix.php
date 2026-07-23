<?php
declare(strict_types=1);
echo 'ini=', var_export(ini_get('intl.default_locale'), true), "\n";
echo 'Locale=', Locale::getDefault(), "\n";
echo 'proc=', locale_get_default(), "\n";

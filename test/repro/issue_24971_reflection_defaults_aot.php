<?php
/**
 * #24971 AOT — version_compare omit-arg + named operator (path helpers segfault on master AOT too).
 */
echo var_export(version_compare('1.0', '1.0'), true), PHP_EOL;
echo (int) version_compare(version1: '2.0', version2: '1.0', operator: '>'), PHP_EOL;

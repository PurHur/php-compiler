<?php
/**
 * AOT: ReflectionExtension::getDependencies — thin proxy (#34155).
 * Zend/VM: date=[] dom[libxml]=Required; AOT pre-fix: NULL.
 */
$date = (new ReflectionExtension('date'))->getDependencies();
$dom = (new ReflectionExtension('dom'))->getDependencies();
echo 'date_type=', gettype($date);
echo ' date_count=', is_array($date) ? count($date) : 'n/a';
echo ' dom_type=', gettype($dom);
echo ' dom_count=', is_array($dom) ? count($dom) : 'n/a';
echo ' dom_libxml=', is_array($dom) ? ($dom['libxml'] ?? '') : '';
echo "\n";

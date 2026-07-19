<?php
// AOT-safe repro for #20739 — avoid is_object/get_class (JIT structGep hazard)
echo 'getLocales=', (int) method_exists(ResourceBundle::class, 'getLocales'), PHP_EOL;
$locales = ResourceBundle::getLocales('ICUDATA');
echo 'locales_ok=', (int) (is_array($locales) && count($locales) > 0), PHP_EOL;
echo 'has_en=', (int) (is_array($locales) && in_array('en', $locales, true)), PHP_EOL;
$rb = ResourceBundle::create('en', null);
echo 'err0=', $rb->getErrorCode(), '|', $rb->getErrorMessage(), PHP_EOL;
$missing = $rb->get('___missing_key_zzz___');
echo 'missing=', var_export($missing, true), PHP_EOL;
echo 'err1=', $rb->getErrorCode(), '|', $rb->getErrorMessage(), PHP_EOL;
$it = $rb->getIterator();
echo 'iterator_ok=', (int) (null !== $it), PHP_EOL;

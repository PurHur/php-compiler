<?php
/**
 * #35059 — AOT preg_replace with string local (TYPE_VALUE) must match Zend.
 */
$h = 'abc123xyz';
echo var_export(preg_replace('/abc(\\d+)xyz/', '$1', $h), true), "\n";
$mime = '=?UTF-8?B?Y2Fmw6k=?=';
echo var_export(preg_replace('/.*=\\?UTF-8\\?B\\?([A-Za-z0-9+\\/=]+)\\?=.*/s', '$1', $mime), true), "\n";
echo var_export(preg_replace('/x/', 'y', 'x'), true), "\n";

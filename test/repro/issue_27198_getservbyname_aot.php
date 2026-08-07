<?php
/**
 * Issue #27198 — AOT getservbyname must match Zend/VM when /etc/services is absent.
 */
var_export(getservbyname('http', 'tcp'));
echo "\n";

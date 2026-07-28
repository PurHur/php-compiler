<?php

declare(strict_types=1);

/**
 * #24439 — LIBXML_RECOVER is PHP 8.4+ only (ext/libxml/libxml.stub.php).
 * Reference PROFILE (unset / 8.2) must match Zend 8.2: defined() false.
 */
echo 'LIBXML_RECOVER=', defined('LIBXML_RECOVER') ? (string) constant('LIBXML_RECOVER') : 'UNDEF', "\n";
echo 'LIBXML_NOENT=', defined('LIBXML_NOENT') ? (string) constant('LIBXML_NOENT') : 'UNDEF', "\n";

--TEST--
DOMDocument::save() writes saveXML() bytes to file (#18435, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root/>');
$tmp = sys_get_temp_dir() . '/dom_save_' . getmypid() . '.xml';
$bytes = $doc->save($tmp);
echo is_int($bytes) ? 'int' : gettype($bytes), "\n";
echo $bytes, "\n";
echo file_get_contents($tmp);
@unlink($tmp);
function dom_save_capture_warning(int $severity, string $message): bool
{
    global $dom_save_warning;
    $dom_save_warning = $message;
    return true;
}
$dom_save_warning = null;
set_error_handler('dom_save_capture_warning');
$fail = $doc->save('/nonexistent/path/dom_save_fail.xml');
restore_error_handler();
echo ($fail === false ? 'false' : 'other'), "\n";
echo null === $dom_save_warning ? '0' : '1', "\n";
if (null !== $dom_save_warning) {
    echo $dom_save_warning, "\n";
}
--EXPECTF--
int
30
<?xml version="1.0"?>
<root/>
false
1
DOMDocument::save(/nonexistent/path/dom_save_fail.xml): Failed to open stream: No such file or directory

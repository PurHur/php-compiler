--TEST--
SimpleXML: simplexml_load_file() missing file — libxml I/O warning text (#25295, ext/simplexml/sxe.c)
--FILE--
<?php
$path = '/nonexistent/path/simplexml_load_file_missing_25295.xml';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = @simplexml_load_file($path);
restore_error_handler();
echo ($result === false) ? "false\n" : "not-false\n";
echo count($warnings), "\n";
echo $warnings[0], "\n";
?>
--EXPECT--
false
1
simplexml_load_file(): I/O warning : failed to load external entity "/nonexistent/path/simplexml_load_file_missing_25295.xml"

--TEST--
stdlib DOMDocument::loadXML() premature-end names innermost open tag (#24319, ext/libxml/libxml.c)
--FILE--
<?php
libxml_use_internal_errors(true);

function dump_case(string $xml): void
{
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $errors = libxml_get_errors();
    $parts = [];
    foreach ($errors as $e) {
        // Locals: multi-property fetch in one sprintf can scramble LibXMLError props on VM.
        $code = $e->code;
        $msg = trim($e->message);
        $line = $e->line;
        $col = $e->column;
        $parts[] = sprintf('%d:%s@%d:%d', $code, $msg, $line, $col);
    }
    echo implode(' | ', $parts), "\n";
}

dump_case('<root><unclosed>');
dump_case('<a><b>');
dump_case('<a><b><c>');
dump_case('<x><y></y>');
dump_case('<root><a/><b>');
dump_case("<root>\n<a>\n");
dump_case('<root><!--x--><a>');
// Keep #18332 contract: incomplete start tag + premature on outer root
libxml_clear_errors();
$doc = new DOMDocument();
$doc->loadXML('<root><unclosed');
$errors = libxml_get_errors();
echo count($errors), ' ';
echo $errors[0]->code, ' ';
echo $errors[1]->code, ' ';
echo str_contains($errors[1]->message, 'Premature end of data in tag root') ? "root77\n" : "noroot\n";
?>
--EXPECT--
77:Premature end of data in tag unclosed line 1@1:17
77:Premature end of data in tag b line 1@1:7
77:Premature end of data in tag c line 1@1:10
77:Premature end of data in tag x line 1@1:11
77:Premature end of data in tag b line 1@1:14
77:Premature end of data in tag a line 2@3:1
77:Premature end of data in tag a line 1@1:18
2 73 77 root77

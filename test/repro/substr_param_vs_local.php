<?php
/**
 * substr($param, $offset + strlen(...)) must keep the param as haystack (#36380).
 *
 * Nested strlen EXEC_RETURN reused the haystack ARG_SEND slot for named CVs,
 * so SEND #0 shipped the length and the remainder was empty — Parsedown hard
 * breaks dropped the following line.
 *
 * php-src: Zend/zend_compile.c ZEND_SEND_VAL — CV operands stay distinct from
 * temporary call results.
 */
function withParam($text)
{
    preg_match("/(?:[ ]*+\\\\|[ ]{2,}+)\n/", $text, $matches, PREG_OFFSET_CAPTURE);
    $offset = $matches[0][1];

    return substr($text, $offset + strlen($matches[0][0]));
}
function withLocal()
{
    $text = "a  \nb";
    preg_match("/(?:[ ]*+\\\\|[ ]{2,}+)\n/", $text, $matches, PREG_OFFSET_CAPTURE);
    $offset = $matches[0][1];

    return substr($text, $offset + strlen($matches[0][0]));
}
echo 'param=';
var_export(withParam("a  \nb"));
echo "\n";
echo 'local=';
var_export(withLocal());
echo "\n";

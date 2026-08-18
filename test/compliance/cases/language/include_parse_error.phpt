--TEST--
language include() of syntax-error file throws catchable ParseError (#32154, Zend/zend_execute.c)
--RUNFILE--
include_parse_error/entry.php
--EXPECT--
ParseError:syntax error, unexpected T_LNUMBER, expecting ';'

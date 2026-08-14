--TEST--
token_get_all/xml_parser_create/xml_parse excess argc → at most/at least (#30890)
--RUNFILE--
../../../repro/issue_30890_token_xml_excess_argc.php
--EXPECT--
token_get_all_hi:ArgumentCountError:token_get_all() expects at most 2 arguments, 3 given
token_get_all_lo:ArgumentCountError:token_get_all() expects at least 1 argument, 0 given
xml_parser_create_hi:ArgumentCountError:xml_parser_create() expects at most 1 argument, 2 given
xml_parse_hi:ArgumentCountError:xml_parse() expects at most 3 arguments, 4 given
xml_parse_lo:ArgumentCountError:xml_parse() expects at least 2 arguments, 0 given
ok_token:1
ok_parser:XMLParser
ok_parse:1

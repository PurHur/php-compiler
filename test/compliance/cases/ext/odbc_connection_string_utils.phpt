--TEST--
ext/odbc odbc_connection_string_* curly-brace helpers (#21256)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
$with_end_curly1 = 'foo}bar';
$with_end_curly2 = '{foo}bar}';
$with_end_curly3 = '{foo}}bar}';
$with_no_end_curly1 = 'foobar';
$with_no_end_curly2 = '{foobar}';

echo "# Is quoted?\n";
echo 'With end curly brace 1: ';
var_dump(odbc_connection_string_is_quoted($with_end_curly1));
echo 'With end curly brace 2: ';
var_dump(odbc_connection_string_is_quoted($with_end_curly2));
echo 'With end curly brace 3: ';
var_dump(odbc_connection_string_is_quoted($with_end_curly3));
echo 'Without end curly brace 1: ';
var_dump(odbc_connection_string_is_quoted($with_no_end_curly1));
echo 'Without end curly brace 2: ';
var_dump(odbc_connection_string_is_quoted($with_no_end_curly2));

echo "# Should quote?\n";
echo 'With end curly brace 1: ';
var_dump(odbc_connection_string_should_quote($with_end_curly1));
echo 'With end curly brace 2: ';
var_dump(odbc_connection_string_should_quote($with_end_curly2));
echo 'With end curly brace 3: ';
var_dump(odbc_connection_string_should_quote($with_end_curly3));
echo 'Without end curly brace 1: ';
var_dump(odbc_connection_string_should_quote($with_no_end_curly1));
echo 'Without end curly brace 2: ';
var_dump(odbc_connection_string_should_quote($with_no_end_curly2));

echo "# Quote?\n";
echo 'With end curly brace 1: ';
var_dump(odbc_connection_string_quote($with_end_curly1));
echo 'With end curly brace 2: ';
var_dump(odbc_connection_string_quote($with_end_curly2));
echo 'With end curly brace 3: ';
var_dump(odbc_connection_string_quote($with_end_curly3));
echo 'Without end curly brace 1: ';
var_dump(odbc_connection_string_quote($with_no_end_curly1));
echo 'Without end curly brace 2: ';
var_dump(odbc_connection_string_quote($with_no_end_curly2));

echo 'a;b should_quote=', var_export(odbc_connection_string_should_quote('a;b'), true), "\n";
echo 'a;b quote=', var_export(odbc_connection_string_quote('a;b'), true), "\n";
echo '{abc} is_quoted=', var_export(odbc_connection_string_is_quoted('{abc}'), true), "\n";
?>
--EXPECT--
# Is quoted?
With end curly brace 1: bool(false)
With end curly brace 2: bool(false)
With end curly brace 3: bool(true)
Without end curly brace 1: bool(false)
Without end curly brace 2: bool(true)
# Should quote?
With end curly brace 1: bool(true)
With end curly brace 2: bool(true)
With end curly brace 3: bool(true)
Without end curly brace 1: bool(false)
Without end curly brace 2: bool(true)
# Quote?
With end curly brace 1: string(10) "{foo}}bar}"
With end curly brace 2: string(13) "{{foo}}bar}}}"
With end curly brace 3: string(15) "{{foo}}}}bar}}}"
Without end curly brace 1: string(8) "{foobar}"
Without end curly brace 2: string(11) "{{foobar}}}"
a;b should_quote=true
a;b quote='{a;b}'
{abc} is_quoted=true

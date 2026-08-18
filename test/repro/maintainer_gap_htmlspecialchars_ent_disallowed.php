<?php

error_reporting(E_ALL);
$ctrl = "\x01";
var_dump(bin2hex(htmlspecialchars($ctrl, ENT_DISALLOWED | ENT_HTML5, 'UTF-8')));
var_dump(bin2hex(htmlentities($ctrl, ENT_DISALLOWED | ENT_HTML5, 'UTF-8')));
var_dump(bin2hex(htmlspecialchars("a\x01b\x7Fc", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')));
var_dump(bin2hex(htmlspecialchars($ctrl, ENT_HTML5, 'UTF-8')));
echo 'tab=', bin2hex(htmlspecialchars("\t", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo 'fdd0=', bin2hex(htmlspecialchars("\u{FDD0}", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo 'xml_del=', bin2hex(htmlspecialchars("\x7F", ENT_DISALLOWED | ENT_XML1, 'UTF-8')), "\n";

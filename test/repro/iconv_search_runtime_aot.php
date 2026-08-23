<?php
function hay() { return "\xC3\xBCber"; }
function ndl() { return 'be'; }
function off() { return 1; }
echo 'strlen=', var_export(iconv_strlen(hay()), true), "\n";
echo 'strpos=', var_export(iconv_strpos(hay(), ndl(), off()), true), "\n";
echo 'strrpos=', var_export(iconv_strrpos(hay(), ndl()), true), "\n";
echo 'miss=', var_export(iconv_strpos(hay(), 'zz'), true), "\n";

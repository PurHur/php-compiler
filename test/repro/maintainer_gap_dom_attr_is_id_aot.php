<?php
// AOT smoke: ClassEntry registration (DomRegistry-backed isId() needs follow-up for user-script Attrs).
var_export(method_exists(DOMAttr::class, 'isId'));
echo "\n";

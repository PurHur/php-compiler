<?php
// Issue #27242 — AOT json_encode(mb_str_split(lit), JSON_UNESCAPED_UNICODE) must match Zend.
// Nested FUNCCALL_INIT must not clobber outer json_encode args; encode folds via JitJsonEncodeCompileTime.
echo json_encode(mb_str_split('こんにちは', 1), JSON_UNESCAPED_UNICODE), "\n";

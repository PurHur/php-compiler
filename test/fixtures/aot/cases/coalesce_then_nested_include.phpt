--TEST--
AOT: nested include after superglobal ?? in layout template (#784, #866, #764)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
coalesce_then_inherited_local/entry_nested.php
--EXPECT--
Home
MiniWebApp

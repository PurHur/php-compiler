--TEST--
AOT: inherited local used after superglobal ?? in included template (#866, #764)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
coalesce_then_inherited_local/entry.php
--EXPECT--
Home

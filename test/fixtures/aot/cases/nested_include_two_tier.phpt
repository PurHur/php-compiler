--TEST--
AOT: two-tier literal include inherits outer scope (#764, #878; next #867 render_home) @group miniwebapp-bisect
--RUNFILE--
nested_include_two_tier/entry.php
--EXPECT--
okdone

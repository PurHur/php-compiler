--TEST--
AOT: require-as-expression return value (MiniWebApp config.php pattern, issues #783, #806, #764) @group miniwebapp-bisect
--RUNFILE--
require_return_config/entry.php
--EXPECT--
FixtureApp

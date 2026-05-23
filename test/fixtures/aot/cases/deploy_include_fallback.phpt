--TEST--
AOT: phpc_deploy_path() falls back to compile tree without PHPC_DEPLOY_ROOT (#623)
--RUNFILE--
deploy_include_template/entry.php
--EXPECT--
compile-tree
--EXPECT_EXIT--
0

--TEST--
Runtime: uncaught Error prints once without Variable::$string secondary fatal (#6357)
--FILE--
<?php
enum E: string { case A = 'x'; }
exit(E::A);
--EXPECT_EXIT--
255

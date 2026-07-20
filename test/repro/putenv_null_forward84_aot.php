<?php
/** AOT soft-null putenv (#21312) — uncaught ValueError after DEP+coerce (EXPECT_EXIT 255). */
error_reporting(E_ALL & ~E_DEPRECATED);
putenv(null);

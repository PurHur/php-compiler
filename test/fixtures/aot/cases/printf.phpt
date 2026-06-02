--TEST--
AOT: printf() formatted stdout write
--FILE--
<?php
printf("id=%d name=%s\n", 9, 'web');
--EXPECT--
id=9 name=web

<?php
// Issue #27389: AOT ftok() must compile and match Zend/VM (no orphan BB).
echo ftok(__FILE__, 'a'), PHP_EOL;

<?php
// #29769 — temporary write context must emit Zend-shaped PHP Fatal error (not parseAndCompile failure).
[1, 2][] = 3;

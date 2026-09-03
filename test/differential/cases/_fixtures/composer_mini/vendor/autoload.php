<?php
// Mini Composer autoload for fixture (#36382). Prefer literal requires so Zend and AOT agree.
require_once __DIR__ . '/../src/Pkg/functions.php';
require_once __DIR__ . '/../src/Pkg/Hello.php';
require_once __DIR__ . '/../src/Classmap/LegacyGreeter.php';

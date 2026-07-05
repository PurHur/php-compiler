<?php

// Non-strict unit: Zend coerces null $format to '' (ext/standard/sprintf.c, #16514).
var_dump(sprintf(null));

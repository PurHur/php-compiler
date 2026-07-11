<?php

// Issue #17829: addcslashes() null $characters coerces to '' without declare(strict_types=1).
var_dump(addcslashes('abc', null));

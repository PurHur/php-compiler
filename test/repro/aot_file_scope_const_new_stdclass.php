<?php

declare(strict_types=1);

// #35196 — file-scope const = new stdClass (empty object)
const C = new stdClass;
var_dump(C);

<?php
/** Repro #34783 — unit enum file-scope const. */
enum U { case A; }
const Y = U::A;
echo Y->name, PHP_EOL;

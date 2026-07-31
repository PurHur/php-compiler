<?php
// Repro #25946 — Zend rejects re-declaring implicit UnitEnum/BackedEnum on enums.
enum S: string implements BackedEnum { case A = "a"; }
echo "ALLOWED\n";

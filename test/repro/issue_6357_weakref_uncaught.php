<?php
// Issue #6357 — uncaught error must not secondary-fatal in ExceptionSupport
stdClass::undefined();

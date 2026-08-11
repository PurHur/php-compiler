<?php
declare(strict_types=1);

try { property_exists(false, 'x'); } catch (\TypeError $e) { echo $e->getMessage() . "\n"; }
try { property_exists(true, 'x'); } catch (\TypeError $e) { echo $e->getMessage() . "\n"; }
try { property_exists(null, 'x'); } catch (\TypeError $e) { echo $e->getMessage() . "\n"; }
try { property_exists(42, 'x'); } catch (\TypeError $e) { echo $e->getMessage() . "\n"; }

<?php

use function Patchwork\always;
use function Patchwork\redefine;

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Patchwork MUST be loaded first
require_once dirname(__DIR__).'/vendor/antecedent/patchwork/Patchwork.php';

// Hardcode Timezone
date_default_timezone_set('America/Los_Angeles');

// Don't ever sleep!
redefine('sleep', always(true));
redefine('usleep', always(true));

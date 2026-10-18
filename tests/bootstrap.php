<?php

use PSP\App\Config;
use PSP\App\Globals;

// Patchwork must be loaded before any code it redefines
require dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

$GLOBALS['is_unit_test'] = true;

\Patchwork\redefine('Amp\delay', \Patchwork\always(null));

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('America/Los_Angeles');
Config::set_base_dir(__DIR__);

\Patchwork\redefine('Monolog\Logger::addRecord', \Patchwork\always(true));
\Patchwork\redefine('sleep', \Patchwork\always(0));
\Patchwork\redefine('usleep', \Patchwork\always(null));

Globals::instance()->is_unit_test = true;

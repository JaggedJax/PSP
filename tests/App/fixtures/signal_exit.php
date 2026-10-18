<?php

// Bootstraps PSP outside the test runner, then sends itself SIGTERM. Used by BootstrapTest
use PSP\App\Bootstrap;
use PSP\App\Config;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Config::set_base_dir(dirname(__DIR__, 2));
Bootstrap::init();

posix_kill(getmypid(), SIGTERM);
// Not reached when the signal handler exits
sleep(5);
echo 'still running';

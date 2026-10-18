<?php

// Dummy job for testing scheduler
// Usage: php dummy_job.php --exit-code=0 --delay=0
$options = getopt('', ['exit-code::', 'delay::']);
$exit_code = (int)($options['exit-code'] ?? 0);
$delay = (int)($options['delay'] ?? 0);

if ($delay > 0) {
	sleep($delay);
}

exit($exit_code);

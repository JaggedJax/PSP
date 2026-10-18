# Scheduler Guide

The PSP Scheduler (`PSP\Process\Scheduler`) is a distributed, database-backed job runner designed for executing recurring and one-time tasks across one or more servers. It handles job queuing, process concurrency limits, process prioritization (`nice`), output logging, timeouts, and graceful shutdown.

---

## Architecture Overview

The Scheduler operates using two distinct components:

1. **Queuer (`RunQueueJobs`)**: Runs once every minute (e.g., via cron). It evaluates cron-style schedules defined in `job_schedules`, identifies enabled jobs from `jobs`, and queues them into `job_log` with status `'Q'` (Queued). A global database lock ensures that only one server performs the queuing pass each minute.
2. **Launcher Daemon (`RunJobLauncher`)**: Runs continuously as a background service on each worker server. It polls for queued jobs (`job_log` and `job_once`), atomically claims them with locks, and launches them as asynchronous processes using [Revolt / Amp EventLoop](https://amphp.org). It tracks concurrency limits, process PIDs, execution time, stdout/stderr, and exit statuses.

```
+-------------------------------------------------------------+
| Cron (every minute) -> Scheduler::RunQueueJobs()            |
| Evaluates job_schedules -> inserts jobs into job_log ('Q')  |
+-------------------------------------------------------------+
                              |
                              v
                  MySQL Database (job_log & job_once)
                              |
                              v
+-------------------------------------------------------------+
| Worker Daemon -> Scheduler::RunJobLauncher()                |
| Claims jobs with atomic locks -> runs concurrent processes  |
+-------------------------------------------------------------+
```

---

## Database Tables

PSP includes the required database tables in `db/schema.sql`:

| Table | Description |
|---|---|
| `jobs` | Recurring job definitions (`job_name`, `path`, `command`, `job_schedule_id`, `allow_duplicates`, `enabled`, `runtime_max_mins`). |
| `job_schedules` | Schedule definitions using cron syntax (`minute`, `hour`, `day_of_month`, `month`, `day_of_week`, `timezone`, `active`). |
| `job_log` | Execution history and queue for recurring jobs. Status values: `Q` (Queued), `R` (Running), `C` (Completed), `T` (Terminated/Timed out). |
| `job_once` | Queue for one-time ad-hoc jobs. Status values: `W` (Waiting), `Q` (Queued), `R` (Running), `C` (Completed), `T` (Terminated). |
| `job_servers` | Worker hostnames, heartbeats, and active launcher process IDs. |
| `jobs_runner` | Records per-minute queuer runs to ensure only one server executes the queue pass for a given minute. |
| `locks` | Database locks used by `PSP\Process\Lock` for distributed synchronization. |

---

## Configuration

Settings are defined in `config/scheduler.json` or via environment variables:

| Setting | Environment Variable | Default | Description |
|---|---|---|---|
| `concurrent_job_limit` | `PSP_SCHEDULER_CONCURRENT_JOB_LIMIT` or `SCHEDULER_CONCURRENT_JOB_LIMIT` | *Required* | Maximum number of child processes allowed to run simultaneously on this server. |
| `nice` | `PSP_SCHEDULER_NICE` or `SCHEDULER_NICE` | `10` | Linux process priority (0 to 19) applied to spawned job processes. |
| `branch_repo` | `PSP_SCHEDULER_BRANCH_REPO` or `SCHEDULER_BRANCH_REPO` | `null` | Optional git repository path for running branch-specific tasks. |

Example `config/scheduler.json`:

```json
{
  "concurrent_job_limit": 10,
  "nice": 10
}
```

---

## Setting Up the Queuer (`RunQueueJobs`)

Create a CLI script that runs once every minute:

```php
#!/usr/bin/env php
<?php
// bin/queue_jobs.php

use PSP\App\Bootstrap;
use PSP\Process\Scheduler;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::init();

$currentTime = date('Y-m-d H:i:00');
$result = Scheduler::RunQueueJobs($currentTime);

if (!$result['success']) {
    // Another server may have claimed this minute, or an error occurred
    exit(0);
}
```

Add a single cron entry on your job servers (or on one designated management server):

```bash
* * * * * php /path/to/app/bin/queue_jobs.php >> /dev/null 2>&1
```

> [!NOTE]
> If multiple servers run this cron job, `Scheduler::RunQueueJobs()` uses a database lock (`job_inserter_{time}`) and checks the `jobs_runner` table so that only the first server to acquire the lock executes the queue pass.

---

## Setting Up the Launcher Daemon (`RunJobLauncher`)

Create a script to run the launcher daemon:

```php
#!/usr/bin/env php
<?php
// bin/job_launcher.php

use PSP\App\Bootstrap;
use PSP\Process\Scheduler;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::init();

// Grabs and launches up to 10 jobs per loop pass
Scheduler::RunJobLauncher(10);
```

### Process Supervision (Systemd Example)

Run the launcher under a process supervisor such as `systemd` to keep it running continuously:

```ini
[Unit]
Description=PSP Job Launcher Daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/app
ExecStart=/usr/bin/php /path/to/app/bin/job_launcher.php
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Signal Handling & Graceful Shutdown

The launcher automatically registers handlers for `SIGTERM` and `SIGINT`:
- On the **first signal**, the launcher stops claiming new jobs and waits for currently running child processes to finish.
- On a **second signal**, the launcher exits immediately.

---

## Defining Recurring Jobs

Jobs are configured in the database tables `job_schedules` and `jobs`.

### 1. Create a Schedule

Schedules follow standard 5-part cron syntax with an optional timezone:

```sql
INSERT INTO job_schedules (name, minute, hour, day_of_month, month, day_of_week, timezone, active)
VALUES ('Hourly Orders Sync', '0', '*', '*', '*', '*', 'America/Los_Angeles', 'Y');

INSERT INTO job_schedules (name, minute, hour, day_of_month, month, day_of_week, timezone, active)
VALUES ('Every 5 Minutes', '*/5', '*', '*', '*', '*', 'UTC', 'Y');
```

### 2. Define the Job

```sql
INSERT INTO jobs (job_name, path, command, job_schedule_id, allow_duplicates, enabled, runtime_max_mins)
VALUES (
  'Sync Orders',
  '/path/to/app/bin',
  'php sync_orders.php',
  1,
  'N',
  'Y',
  60
);
```

- `allow_duplicates`: Set to `'N'` to prevent a job from launching if an earlier run of the same job is still running.
- `runtime_max_mins`: Maximum execution time in minutes. The launcher terminates jobs that exceed this duration.

---

## Queuing One-Time Jobs (`add_one_time_job`)

To schedule an ad-hoc, asynchronous task from your web or CLI application, use `Scheduler::add_one_time_job()`:

```php
use PSP\Process\Scheduler;

$jobOnceId = Scheduler::add_one_time_job([
    'path' => '/path/to/app/bin',
    'command' => 'php generate_report.php --report-id=42',
    'run_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
    'runtime_max_mins' => 30,
    'user_id' => 123, // Used for optional rate limiting
], rate_limit: true);

if ($jobOnceId === false) {
    // Rate limit hit or failed to schedule
}
```

- Deduplication: If an identical job (`path` and `command`) was already scheduled within the last 24 hours and is still pending/running, `add_one_time_job()` returns the existing ID without creating a duplicate.
- Rate Limiting: When `$rate_limit = true`, `RateLimiter` restricts users from queuing too many jobs within a short window.

---

## Writing Job Scripts

Job scripts should initialize PSP and handle locking if they require mutual exclusion:

```php
#!/usr/bin/env php
<?php
// bin/sync_orders.php

use PSP\App\Bootstrap;
use PSP\Log\Log;
use PSP\Process\Lock;
use PSP\Process\Scheduler;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::init();

// Acquire an exclusive global lock so instances don't collide
$lock = new Lock('sync_orders', Lock::Global);
if (!$lock->get_exclusive()) {
    Log::info('Sync Orders is already running. Skipping.');
    // Exit code 95 tells the Scheduler that this run was intentionally skipped
    exit(Scheduler::EXIT_STATUS_SKIPPED);
}

try {
    Log::info('Starting order synchronization...');
    // ... perform work ...
    Log::info('Order synchronization completed successfully.');
    exit(0);
} catch (\Throwable $e) {
    Log::emergency('Order synchronization failed: ' . $e->getMessage());
    exit(1);
} finally {
    $lock->release_lock();
}
```

### Exit Codes

- `0`: Job completed successfully.
- `95` (`Scheduler::EXIT_STATUS_SKIPPED`): The job skipped its run intentionally (for instance, when unable to acquire its application lock). The launcher records this status without treating it as an unexpected failure.
- Non-zero (other than 95): Recorded as a job failure in `job_log.exit_status`. Output captured from stdout and stderr is saved in logs for diagnosis.

# PSP

PHP Starter Project, aka PHP PSP

The goal of PSP is to build a set of easy-to-use basic building blocks to help anyone quickly get started with a PHP project.
PSP is not aiming to be the most flexible and powerful set of tools, but the most practical tools with a very low learning curve and sane defaults.

Requires PHP 8.5 and MySQL 8.4.

## Install

```bash
composer require jaggedjax/psp
```

Create the PSP tables in your database:

```bash
mysql myapp < vendor/jaggedjax/psp/db/schema.sql
```

You can add app columns to these tables. For example, `jobs.customer_id`.

## Modules

| Namespace | Classes |
|---|---|
| `PSP\App` | `Config` (JSON config files), `Globals` (process-wide values), `Server` (host, IP, stack trace) |
| `PSP\Db` | `DB` (PDO wrapper with replica support), `DBTable` (base class for table objects), `DBTableIterator`, `History` (change log), `Dictionary`, `CustomGenerator` |
| `PSP\Cache` | `MemCache` (Memcached), `DataCache` (DB-backed cache with fallback functions), `RateLimiter` |
| `PSP\Log` | `Log` (Monolog JSON logs), `LogTrail` (collect Log IDs for one unit of work), `Telemetry` (OpenTelemetry span tags), `LokiLink` (Grafana links) |
| `PSP\Http` | `Http` (curl client with retries, OAuth refresh, rate limits), `HttpGraphObj` (GraphQL) |
| `PSP\Process` | `CLI` (arguments and help), `Lock` (DB locks across servers), `Scheduler` (cron-like job runner) |
| `PSP\Files` | `CSV`, `Ftp`, `Sftp` (needs `phpseclib/phpseclib`) |
| `PSP\Util` | `Crypt`, `Strings`, `Dates`, `Math`, `Money`, `Html` (HTML sanitizer), `Password` (rules and breach check), `ProfanityFilter` |

## Config

PSP reads JSON files from the `config/` dir of your project. See `config_example/`.

- `psp.json`: `name`, `environment` (local, dev, stage, prod), `domain`, `log_dir`, `grafana`
- `db.json`: `host`, `replica_host`, `name`, `username`, `password`
- `scheduler.json`: `concurrent_job_limit`, `nice`, `branch_repo`

Logs go to stderr when `log_dir` is not set.

## Bootstrap

Set app-specific values once, before other PSP calls:

```php
use PSP\App\Config;
use PSP\Cache\DataCache;
use PSP\Cache\MemCache;
use PSP\Db\DBTable;
use PSP\Db\History;
use PSP\Log\LogProcessor;
use PSP\Log\Telemetry;

require __DIR__ . '/vendor/autoload.php';

// Only needed when the project root is not the dir that contains vendor/
Config::set_base_dir(__DIR__);

// Specify the namespace of your Database objects. Used for calling DBTable::incoming() w/o needing top specify the full object name w/ the namespace
DBTable::$_object_namespace = 'MyApp\\Objects\\';

// App IDs to keep as top-level log fields and span attributes
LogProcessor::add_fields('customer_id');
Telemetry::add_taggable_ids('customer_id');
Telemetry::$attribute_prefix = 'myapp';

// Extra DataCache levels. Lock levels are free strings
DataCache::add_cache_levels('Customer');

// Who made a change, for History entries
History::set_changed_by_resolver(fn () => isset($_SESSION['user_id']) ? 'user_id:' . $_SESSION['user_id'] : null);

MemCache::connect('127.0.0.1');
```

## Development

```bash
composer install
composer test -- --testsuite Unit
composer stan
composer cs
```

The Database suite needs a MySQL database with `db/schema.sql` loaded. Connection settings are in `tests/config/db.json`.

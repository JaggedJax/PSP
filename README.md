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

Settings can be defined in JSON files in the `config/` dir, via environment variables, or programmatically via `Config::set()`.

### Precedence

1. **In-memory**: `Config::set('db', [...])` (highest, useful for tests and runtime bootstrapping)
2. **Specific PSP environment variables**: `PSP_DB_PASSWORD`, `PSP_NAME`, etc.
3. **Standard environment aliases**: `DB_HOST`, `DB_PASSWORD`, `APP_ENV`, etc.
4. **Full JSON payload env var**: `PSP_CONFIG_{TYPE}` or `PSP_{TYPE}_JSON`
5. **JSON file**: `config/{type}.json` (lowest, baseline defaults)

If a JSON file exists, environment variables override matching keys. If no JSON file exists, configuration can be driven entirely by environment variables.

### Common Environment Variables

#### Database (`config/db.json` / `Config::get('db')`)

| Environment Variable | Alias | JSON Key | Example |
|---|---|---|---|
| `PSP_DB_HOST` | `DB_HOST` | `host` | `127.0.0.1:3306` |
| `PSP_DB_REPLICA_HOST` | `DB_REPLICA_HOST` | `replica_host` | `127.0.0.1:3306` |
| `PSP_DB_NAME` | `DB_NAME`, `DB_DATABASE` | `name` | `myapp` |
| `PSP_DB_USERNAME` | `DB_USERNAME`, `DB_USER`, `PSP_DB_USER` | `username` | `myapp` |
| `PSP_DB_PASSWORD` | `DB_PASSWORD` | `password` | `secret` |

#### App & Telemetry (`config/psp.json` / `Config::get('psp')`)

| Environment Variable | Alias | JSON Key | Example |
|---|---|---|---|
| `PSP_NAME` | `APP_NAME` | `name` | `MyApp` |
| `PSP_ENVIRONMENT` | `PSP_ENV`, `APP_ENV`, `ENVIRONMENT` | `environment` | `prod` (local, dev, stage, prod) |
| `PSP_DOMAIN` | `APP_DOMAIN` | `domain` | `example.com` |
| `PSP_LOG_DIR` | `LOG_DIR` | `log_dir` | `/var/log/myapp` (defaults to `php://stderr`) |
| `PSP_MEMCACHED_SERVER` | `MEMCACHED_SERVER`, `MEMCACHED_HOST` | `memcached.server` | `127.0.0.1` |
| `PSP_MEMCACHED_PORT` | `MEMCACHED_PORT` | `memcached.port` | `11211` |
| `PSP_GRAFANA_URL` | `GRAFANA_URL` | `grafana.url` | `http://localhost:3000` |
| `PSP_GRAFANA_LOKI_DATASOURCE_UID` | `GRAFANA_LOKI_DATASOURCE_UID` | `grafana.loki_datasource_uid` | `loki` |
| `PSP_GRAFANA_LOKI_SELECTOR` | `GRAFANA_LOKI_SELECTOR` | `grafana.loki_selector` | `{service_name="php"}` |

#### Scheduler (`config/scheduler.json` / `Config::get('scheduler')`)

| Environment Variable | Alias | JSON Key | Example |
|---|---|---|---|
| `PSP_SCHEDULER_CONCURRENT_JOB_LIMIT` | `SCHEDULER_CONCURRENT_JOB_LIMIT` | `concurrent_job_limit` | `10` |
| `PSP_SCHEDULER_NICE` | `SCHEDULER_NICE` | `nice` | `10` |
| `PSP_SCHEDULER_BRANCH_REPO` | `SCHEDULER_BRANCH_REPO` | `branch_repo` | `git@github.com:...` |

#### HaveIBeenPwned (`Config::get('hibp')`)

| Environment Variable | Alias | JSON Key | Example |
|---|---|---|---|
| `PSP_HIBP_ENABLED` | `HIBP_ENABLED` | `enabled` | `true` / `false` |

### Custom & Nested Configuration

For custom config files or arbitrary keys (e.g. `Config::get('payment')`), set env vars using the `PSP_{CONFIG}_{KEY}` convention. Use double underscores (`__`) to denote nested array paths:

```bash
PSP_PAYMENT_API_KEY="sk_live_12345"
PSP_PAYMENT_WEBHOOK__SECRET="whsec_67890"
```

To provide an entire config type as a JSON payload (useful for Kubernetes or cloud secrets managers):

```bash
PSP_CONFIG_DB='{"host":"127.0.0.1:3306","name":"myapp","username":"root","password":"secret"}'
```


## Bootstrap

Set app-specific values once, before other PSP calls:

```php
use PSP\App\Config;
use PSP\App\Server;
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

// Proxy headers that hold the client IP. Without this, Server::get_ip() uses REMOTE_ADDR
Server::trust_ip_headers('HTTP_CF_CONNECTING_IP');

// RateLimiter needs MemCache. Without a connection it allows every request
MemCache::connect('127.0.0.1');
```

## Development

```bash
composer install
composer test
composer stan
composer cs
```

`composer test:db` runs the Database suite. It needs a MySQL database with `db/schema.sql` loaded. Connection settings are in `tests/config/db.json`.

## Contributions & AI

This project is designed to make spinning up new PHP projects quick and easy for me, with common reusable components. It is not intended to solve every problem.

Contributions are welcome as long as they keep to the style of this repository and are not specific to your application.
I am publishing this primarily for myself and not looking to create another job for myself, so please be clear and patient with any requests.
If you don't like the directions or decisions I make, you are encouraged to fork and expand upon this work.
If you find this project too limiting, you can take the code as a starting point and personalize it from there.

This project was initially handwritten and then improved with the help of AI. AI-generated code is allowed, however quality and readability are the most important factors

## License

This project is licensed under the Mozilla Public License 2.0 - see the [LICENSE](LICENSE) file for details.

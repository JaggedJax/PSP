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

Call `Bootstrap::init()` once in every entry point (web front controller and CLI scripts), right after the Composer autoloader:

```php
use PSP\App\Bootstrap;

require __DIR__ . '/vendor/autoload.php';

Bootstrap::init([
	'timezone' => 'America/Los_Angeles',
	'object_namespace' => 'MyApp\\Objects\\',
	'log_fields' => ['customer_id'],
	'trace_prefix' => 'myapp',
	'trusted_ip_headers' => ['HTTP_CF_CONNECTING_IP'],
	'cache_levels' => ['Customer'],
	'changed_by' => fn () => isset($_SESSION['user_id']) ? 'user_id:' . $_SESSION['user_id'] : null,
	'session_lifetime' => 43200,
	'nonce_header' => 'HTTP_X_CSP_NONCE',
	'csp' => [
		'default-src' => ["'self'"],
		'script-src' => ["'strict-dynamic'", "'nonce-{nonce}'"],
	],
	'hsts_max_age' => 31536000,
]);
```

`init()` always:
- Tags the trace with the environment
- Connects to Memcached from `psp.json` `memcached` (or the `memcached` option). RateLimiter needs it. Without a connection it allows every request
- Starts an OpenTelemetry root span for the request or job (not in `local` or unit tests). The SDK is set with the standard `OTEL_*` env vars and is a no-op when off
- For web requests: sets the process name to `web` and sends the CSP and `Referrer-Policy` headers
- Registers a shutdown handler for normal exit, SIGTERM and SIGINT. It releases the locks of CLI scripts and logs fatal errors. Pass `'shutdown_handler' => false` to skip it

| Option | Sets |
|---|---|
| `timezone` | `date_default_timezone_set()`. DB connections use the same offset |
| `object_namespace` | `DBTable::$_object_namespace`, used for short class names in `DBTable::incoming()` |
| `log_fields` | App ID fields kept as top-level log fields and tagged on the trace (`Log::add_fields()`) |
| `trace_prefix` | Prefix of trace attributes (`Telemetry::$attribute_prefix`). Default `app` |
| `tracer_name` | OpenTelemetry tracer name. Default `psp` |
| `trusted_ip_headers` | Proxy headers that hold the client IP (`Server::trust_ip_headers()`). Without them, `Server::get_ip()` uses `REMOTE_ADDR` |
| `cache_levels` | Extra DataCache levels. Lock levels are free strings |
| `changed_by` | Function that returns who made a change, for History entries |
| `memcached` | `['server' => ..., 'port' => ...]`. Default: `psp.json` `memcached` |
| `session_lifetime` | Seconds for `session.gc_maxlifetime`. The session cookie ends with the browser |
| `nonce_header` | `$_SERVER` key of a request header that can set the CSP nonce, for HTMX partial updates |
| `csp` | Content-Security-Policy directives. `{nonce}` is replaced with the request nonce (`Globals::instance()->nonce`) |
| `referrer_policy` | Default `strict-origin-when-cross-origin` |
| `hsts_max_age` | Sends `Strict-Transport-Security` outside `dev` and `local` |

To add log fields later, without tagging the trace: `Log::add_fields(['order_id'], trace: false)`.

Only call `Config::set_base_dir()` (before `init()`) when the project root is not the dir that contains `vendor/`.

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

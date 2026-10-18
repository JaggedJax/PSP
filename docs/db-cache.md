# DBCache Guide

`PSP\Cache\DBCache` provides a persistent, database-backed caching layer (using the `data_cache` table) designed for expensive or slow operations—such as third-party API responses, heavy report aggregations, or external web service results.

Unlike ephemeral in-memory caches (such as Memcached or Redis), DBCache persists across server restarts and does not consume memory when idle. It includes built-in fallback callable execution, granular multi-tenant cache levels, automatic serialization, and flexible cache-invalidation modes.

---

## Architecture & Concepts

### 1. Database Storage

Data is stored in the `data_cache` MySQL table (defined in `db/schema.sql`):
- `cache_level`: Scope of the cache (e.g., `Global`, `Customer`, `Store`).
- `cache_level_pk`: Primary key within that level (always `0` for `Global`).
- `cache_name`: Descriptive identifier for the cached dataset.
- `data`: Serialized payload (`mediumtext`).
- `data_type`: Supported types: `'json'` (default) or `'string'`.
- `expiration_date`: Timestamp when the entry expires.

### 2. Cache Levels

By default, only the `'Global'` cache level is available. To scope cache entries to application entities (such as customers or accounts), register custom levels:

```php
use PSP\App\Bootstrap;
use PSP\Cache\DBCache;

// During bootstrap:
Bootstrap::init([
    'cache_levels' => ['Customer', 'Store', 'Vendor'],
]);

// Or at runtime:
DBCache::add_cache_levels('Customer', 'Store');
```

---

## Core API: `DBCache::get()`

The primary method for reading and populating cached data is `DBCache::get()`:

```php
public static function get(
    string $cache_level,
    int $pk,
    string $cache_name,
    array $fallback_function,
    int $cache_handling = DBCache::DEFAULT_CACHE
): ?DBCacheResult
```

### Fallback Callable Structure

The `$fallback_function` parameter is an array with:
- `'callable'`: The function, closure, or method to execute if the cache misses or is expired.
- `'args'`: Array of arguments passed to the callable.

The fallback callable **must return a `DBCacheResult` object** (or `null` if the data could not be retrieved).

---

## `DBCacheResult`

The `DBCacheResult` class encapsulates cached data and metadata:

```php
use PSP\Cache\DBCacheResult;

$result = new DBCacheResult(
    data: ['score' => 98.5, 'verified' => true],
    cache_hours: 12, // Number of hours before expiration
    data_type: 'json' // 'json' or 'string'
);
```

### Useful Methods:
- `$result->get_data()`: Returns decoded data (e.g., an associative array for JSON, or string).
- `$result->as_array()`: Returns an array with keys `data`, `data_type`, `last_updated`, `expiration_date`, and `expired`.
- `$result->expired`: Boolean flag indicating whether the cached entry is past its expiration date.
- `$result->get_last_updated()`: Timestamp string of when the data was saved.
- `$result->get_expiration_date()`: Timestamp string of expiration.

---

## Cache Handling Modes (Flags)

Control cache read and fallback behavior using the `$cache_handling` bitmask:

| Flag | Value | Description |
|---|---|---|
| `DBCache::DEFAULT_CACHE` | `0` | Standard behavior: Return fresh cached data if available. If missing or expired, execute the fallback callable, save the result, and return it. |
| `DBCache::INCLUDE_EXPIRED` | `1` | Return expired data if it exists. Fallback is executed if expired or missing, allowing a stale-while-revalidate pattern. |
| `DBCache::FORCE_REFRESH` | `2` | Skip checking existing cache. Always execute the fallback callable, update the database entry, and return the fresh result. |
| `DBCache::CACHED_ONLY` | `4` | Only return existing cached data. Never execute the fallback callable (returns `null` if missing or expired). |
| `DBCache::NO_SAVE` | `8` | Execute the fallback callable if necessary, but do not write the result back to the database. |

Flags can be combined using bitwise OR:

```php
// Force refresh data, but do not persist it to the cache table
$result = DBCache::get(
    'Customer',
    123,
    'credit_score',
    ['callable' => $fetchFunction, 'args' => [123]],
    DBCache::FORCE_REFRESH | DBCache::NO_SAVE
);
```

---

## Complete Examples

### Example 1: Global Cache (Exchange Rates)

```php
use PSP\Cache\DBCache;
use PSP\Cache\DBCacheResult;

function fetch_exchange_rates(string $baseCurrency): DBCacheResult
{
    // Simulate third-party API call
    $rates = [
        'base' => $baseCurrency,
        'rates' => ['EUR' => 0.92, 'GBP' => 0.79, 'JPY' => 155.20],
    ];

    // Cache the result for 24 hours
    return new DBCacheResult($rates, cache_hours: 24);
}

// Global level always uses PK 0
$cache = DBCache::get(
    cache_level: 'Global',
    pk: 0,
    cache_name: 'exchange_rates_usd',
    fallback_function: [
        'callable' => 'fetch_exchange_rates',
        'args' => ['USD'],
    ]
);

if ($cache !== null) {
    $rates = $cache->get_data();
    echo "1 USD = " . $rates['rates']['EUR'] . " EUR\n";
    echo "Last updated: " . $cache->get_last_updated() . "\n";
}
```

### Example 2: Entity-Scoped Cache (Customer Analytics)

```php
use PSP\Cache\DBCache;
use PSP\Cache\DBCacheResult;

class AnalyticsService
{
    public function getCustomerMetrics(int $customerId): ?DBCacheResult
    {
        // Expensive database aggregation or CRM API call
        $metrics = [
            'total_spend' => 4520.50,
            'orders_count' => 38,
            'churn_risk' => 'low',
        ];

        // Cache for 6 hours
        return new DBCacheResult($metrics, cache_hours: 6);
    }
}

$analytics = new AnalyticsService();
$customerId = 42;

// Retrieve from cache or compute via fallback
$result = DBCache::get(
    cache_level: 'Customer',
    pk: $customerId,
    cache_name: 'analytics_summary',
    fallback_function: [
        'callable' => [$analytics, 'getCustomerMetrics'],
        'args' => [$customerId],
    ]
);

if ($result !== null) {
    $summary = $result->get_data();
    echo "Total Spend: \${$summary['total_spend']}\n";
}
```

### Example 3: Manual Saving (`manual_save`)

If your application receives a webhook or precomputes data in a background job, write it directly to the cache without triggering a lookup:

```php
use PSP\Cache\DBCache;
use PSP\Cache\DBCacheResult;

$webhookData = ['status' => 'approved', 'transaction_id' => 'tx_998877'];
$cacheResult = new DBCacheResult($webhookData, cache_hours: 48);

DBCache::manual_save(
    cache_level: 'Customer',
    pk: 123,
    cache_name: 'latest_transaction',
    cache_object: $cacheResult
);
```

# Http Client Guide

The PSP HTTP client (`PSP\Http\Http`) is an enterprise-grade cURL wrapper designed for interacting with external REST APIs and GraphQL endpoints. It provides connection persistence, automatic retries with backoff, rate limit handling (HTTP 429), OAuth 2.0 token refresh automation, credential sanitization in logs, and detailed telemetry metrics.

---

## Core Philosophy

- **Instance Persistence**: `Http` is designed to be instantiated once per service and reused across requests. It keeps cURL connection handles alive to reuse TCP and TLS handshakes.
- **JSON First**: Out-of-the-box, JSON is used for encoding request payloads and decoding response bodies.
- **Safe by Default**: Automatically strips sensitive headers (such as `Authorization`) and data fields before writing requests to Monolog logs.
- **Standardized User-Agent**: Generates a professional User-Agent header (e.g. `MyApp/1.0 (+https://example.com)`) based on `Config::get('psp')` settings to avoid generic bot-blocking.

---

## Basic Usage

### Instantiation

```php
use PSP\Http\Http;

$http = new Http('https://api.stripe.com/v1/');
```

### Making Requests

`Http` provides convenience methods for all standard HTTP verbs:

```php
// GET request with query parameters: /customers?limit=10&status=active
$customers = $http->get('customers', ['limit' => 10, 'status' => 'active']);

// POST request with JSON payload and custom header
$newCustomer = $http->post(
    endpoint: 'customers',
    url_params: [],
    post_data: [
        'name' => 'Alice Johnson',
        'email' => 'alice@example.com',
    ],
    header: ['Idempotency-Key: ' . uniqid()]
);

// PUT request
$updated = $http->put('customers/cus_123', [], ['name' => 'Alice Smith']);

// PATCH request
$patched = $http->patch('customers/cus_123', [], ['metadata' => ['plan' => 'pro']]);

// DELETE request
$deleted = $http->delete('customers/cus_123');
```

---

## Response Metadata & Telemetry

Inspect detailed status, timing, and raw payloads after every call:

```php
$response = $http->get('users/42');

$statusCode   = $http->get_last_http_code();      // e.g., 200, 404, 500
$runTime      = $http->get_last_run_time();       // Execution duration in seconds (e.g. 0.142)
$rawRequest   = $http->get_last_raw_request();    // Exactly what was sent
$rawResponse  = $http->get_last_raw_response();   // Unparsed body from the server
$logId        = $http->get_last_log_id();         // Monolog Log ID for tracing this call

// Response headers
$contentType  = $http->get_response_header('content-type');
$allHeaders   = $http->get_response_headers();
```

---

## Global Headers & Query Parameters

Set headers or parameters that must accompany every subsequent request:

```php
$http = new Http('https://api.github.com/');

// Global headers
$http->set_global_headers([
    'Authorization: Bearer ghp_your_token_here',
    'Accept: application/vnd.github+json',
    'X-GitHub-Api-Version: 2022-11-28',
]);

// Global GET parameters (added to all GET, POST, PUT, DELETE requests)
$http->set_global_get_params([
    'app_id' => 'psp-integration',
]);
```

---

## Automatic Retries & Rate Limiting

### Auto-Retries on Server Errors

Automatically retry failed requests caused by network drops or transient 5xx server errors:

```php
// Retry up to 3 times, sleeping 2 seconds between attempts
$http->set_num_auto_retries(3);
$http->set_auto_retry_sleep_time(2);
```

### Rate Limiting (HTTP 429)

Handle API rate limits automatically. When a specified HTTP status is received, `Http` pauses execution and retries:

```php
// Watch for HTTP 429 Too Many Requests (default)
$http->set_http_rate_limit_code([429]);

// Sleep for 30 seconds before retrying
$http->set_http_rate_limit_sleep(30);
```

---

## OAuth 2.0 Automatic Token Refresh

`Http` can automatically refresh expired tokens when receiving an authentication failure (e.g., HTTP 401 Unauthorized), update its internal authorization header, and transparently retry the original request:

```php
use PSP\Http\Http;

class AuthService
{
    public function refreshAccessToken(int $accountId): string
    {
        // Request a new access token from OAuth provider
        $newToken = 'fresh_access_token_' . time();
        // Persist token to database...
        return $newToken;
    }
}

$auth = new AuthService();
$accountId = 55;

$http = new Http('https://api.service.com/v2/');

// Configure automated OAuth refresh
$http->oauth_refresh_setup(
    http_statuses: [401], // Refresh when receiving HTTP 401
    callable_function: [$auth, 'refreshAccessToken'], // Callable to fetch the new token
    function_params: [$accountId], // Arguments passed to the callable
    oauth_header_update_function: fn ($newToken) => "Authorization: Bearer $newToken"
);

// If the access token expired, Http catches the 401, invokes refreshAccessToken(),
// applies the new Authorization header, and retries the call seamlessly:
$data = $http->get('protected/resource');
```

---

## Log Sanitization (`strip_credentials`)

Prevent credentials, tokens, and confidential customer data from leaking into logs:

```php
$http->strip_credentials(
    headers: ['Authorization', 'X-Api-Key'],
    get_params: ['token', 'secret'],
    data_fields: ['password', 'card_number', 'cvv']
);
```

Matching values in request headers, query strings, and request bodies are replaced with `[STRIPPED]` in log outputs.

---

## Custom Builders & Parsers

Tailor `Http` to work with XML, form-encoded APIs, or non-standard protocols:

### Form-Urlencoded POSTs

```php
// Use http_build_query instead of json_encode for request bodies
$http->custom_post_builder('http_build_query');
$http->set_global_headers(['Content-Type: application/x-www-form-urlencoded']);
```

### Custom Response Parsing (XML / CSV)

```php
// Parse responses with simplexml_load_string instead of json_decode
$http->custom_response_parser(function (string $rawBody) {
    return simplexml_load_string($rawBody);
});
```

### Dynamic / Random Headers

Inject dynamic values (such as nonces, timestamps, or request signatures) into every request:

```php
$http->random_header(
    rand_header_name: 'X-Request-Timestamp',
    rand_header_function: fn () => (string)time()
);
```

---

## GraphQL Support (`HttpGraphObj`)

PSP provides a dedicated GraphQL query and mutation builder via `PSP\Http\HttpGraphObj`:

```php
use PSP\Http\Http;
use PSP\Http\HttpGraphObj;
use PSP\Log\Log;

$http = new Http('https://api.example.com/graphql');

// Create a query object
$graph = new HttpGraphObj('query', 'GetUserOrders', Log::getInstance());

// Build fragments and fields
$graph->add_fragment('OrderDetails', [
    'orderId',
    'status',
    'totalAmount',
], 'Order');

// Execute the GraphQL query
$response = $http->query(
    endpoint: '',
    url_params: ['userId' => 123],
    qo: $graph
);
```

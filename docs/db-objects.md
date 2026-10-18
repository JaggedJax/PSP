# DB Objects (`DBTable`) Guide

PSP's `PSP\Db\DBTable` provides a lightweight Active Record pattern tailored for modern PHP 8.5+. It utilizes PHP 8.4+ property hooks for automatic dirty-field tracking, transparent primary key caching via Memcached, relationships, streaming iterators, and automatic change auditing.

---

## Conventions & Rules

When extending `DBTable`:

1. **Table Columns**: Public properties whose names match the database column names (no leading underscores).
2. **Property Hooks**: Use the `set` property hook with `$this->db_set_property_check('field_name', $value)` on column properties to enable change tracking and validation.
3. **Internal / Non-Column Properties**: Properties that are not database columns must start with a single underscore (e.g., `public string $_temporary_token = '';`).
4. **Outgoing Relationships (Belongs-To)**: Properties linking to parent tables must start with a double underscore (e.g., `public ?Customer $__customer = null;`).
5. **Table Configuration (`$_table`)**: Must define a static array with `name`, `pk_field`, and `pk_type`. Optional keys: `incoming` (one-to-many references) and `lazy` (columns excluded from initial `SELECT`).

---

## Sample Class Implementation

Here is a complete example defining a `Customer` model and an `Order` model with relationships, lazy loading, and auto-caching.

### `Customer.php`

```php
<?php

namespace MyApp\Objects;

use PSP\Db\DBTable;

/**
 * @extends DBTable<Customer>
 */
class Customer extends DBTable
{
    /** @var array{name:string, pk_field:string, pk_type:string, incoming?:array<class-string, string>, lazy?:string[]} */
    public static array $_table = [
        'name' => 'customers',
        'pk_field' => 'customer_id',
        'pk_type' => 'int',
        // Define incoming (one-to-many) relationships: ClassName => foreign_key_col_on_remote_table
        'incoming' => [
            Order::class => 'customer_id',
        ],
        // Columns excluded from initial SELECT; loaded on first access
        'lazy' => ['notes'],
    ];

    /** Automatically cache instances in Memcached for 1 hour (3600 seconds) */
    protected static int $_auto_cache = 3600;

    // Primary key
    public ?int $customer_id = null;

    // Columns with change tracking hooks
    public string $name = '' {
        set(string $value) => $this->db_set_property_check('name', $value);
    }

    public string $email = '' {
        set(string $value) => $this->db_set_property_check('email', $value);
    }

    public string $status = 'active' {
        set(string $value) => $this->db_set_property_check('status', $value);
    }

    // Lazy-loaded field: getter triggers load_lazy() on first read
    public ?string $notes = null {
        get {
            $this->load_lazy('notes');
            return $this->notes;
        }
        set(?string $value) => $this->db_set_property_check('notes', $value);
    }

    // Non-column property (starts with single _)
    public string $_display_label = '';
}
```

### `Order.php`

```php
<?php

namespace MyApp\Objects;

use PSP\Db\DBTable;

/**
 * @extends DBTable<Order>
 */
class Order extends DBTable
{
    public static array $_table = [
        'name' => 'orders',
        'pk_field' => 'order_id',
        'pk_type' => 'int',
    ];

    public ?int $order_id = null;

    public int $customer_id = 0 {
        set(int $value) => $this->db_set_property_check('customer_id', $value);
    }

    public float $total_amount = 0.0 {
        set(float $value) => $this->db_set_property_check('total_amount', $value);
    }

    public string $created_at = '' {
        set(string $value) => $this->db_set_property_check('created_at', $value);
    }

    // Outgoing linked table (starts with double __)
    public ?Customer $__customer = null;
}
```

---

## CRUD Operations

### 1. Creating New Records

```php
use MyApp\Objects\Customer;

$customer = new Customer();
$customer->name = 'Jane Doe';
$customer->email = 'jane@example.com';
$customer->status = 'active';

// Save writes only dirty fields and assigns the auto-increment PK
$newId = $customer->save();
echo "Created customer with ID: $newId\n";
```

> [!TIP]
> **Property Defaults & `mark_field_changed()`**: Property hooks only detect changes when assigned a value *different* from the property's initial value. If inserting a record where a column must be explicitly saved with its default value (e.g. `status = 'active'`), call `$customer->mark_field_changed('status')` before saving.

### 2. Loading Records by Primary Key

```php
// Load single record (cached if $_auto_cache is configured)
$customer = Customer::load_from_pk(42);

if ($customer !== null) {
    echo "Found: {$customer->name} ({$customer->email})\n";
}

// Load multiple records in one query
$customers = Customer::load_from_pks([10, 20, 30]);
```

### 3. Querying Records (`load_from`)

`load_from()` accepts where conditions, ordering, limits, and optional load rules:

```php
// Find all active customers ordered by name
$activeCustomers = Customer::load_from(
    where: ['status' => 'active'],
    orderby: 'name ASC',
    limit: 50
);

// Advanced conditions: operators
$recentHighValue = Order::load_from(
    where: [
        'total_amount' => ['op' => '>=', 'val' => 100.00],
        'customer_id' => [1, 2, 3], // Translates to IN (1, 2, 3)
    ],
    orderby: 'created_at DESC'
);

// Count matching records
$totalActive = Customer::count(['status' => 'active']);
```

### 4. Streaming Large Result Sets (`load_from_iterate`)

To iterate over millions of rows without loading them all into memory, use `load_from_iterate()`. It returns a `DBTableIterator` that streams rows in batches:

```php
$iterator = Customer::load_from_iterate(
    where: ['status' => 'active'],
    orderby: 'customer_id ASC',
    batch_size: 500
);

foreach ($iterator as $customer) {
    // Process $customer one by one; memory remains constant
    process_customer($customer);
}
```

### 5. Updating Records

Only fields that have been modified are updated in the database:

```php
$customer = Customer::load_from_pk(42);
if ($customer !== null) {
    $customer->email = 'jane.new@example.com';
    // Generates: UPDATE customers SET email = 'jane.new@example.com' WHERE customer_id = 42
    $customer->save();
}
```

### 6. Deleting Records

```php
$customer = Customer::load_from_pk(42);
if ($customer !== null) {
    $customer->delete(); // Also invalidates the Memcached entry automatically
}
```

### 7. Converting to Array

```php
$data = $customer->to_array();
// Returns ['customer_id' => 42, 'name' => 'Jane Doe', 'email' => '...', ...]
```

---

## Working with Relationships

### Incoming References (One-to-Many)

When `$_table['incoming']` is configured, retrieve linked child records using the `incoming()` method:

```php
$customer = Customer::load_from_pk(42);

// Access child orders as a DBTableIterator
$orders = $customer->incoming(Order::class);

foreach ($orders as $order) {
    echo "Order #{$order->order_id}: \${$order->total_amount}\n";
}
```

> [!NOTE]
> If `Bootstrap::init(['object_namespace' => 'MyApp\\Objects\\'])` is set, you can pass short class names:
> ```php
> $orders = $customer->incoming('Order');
> ```

---

## Auto-Caching & Cache Invalidation

When `protected static int $_auto_cache` is greater than 0:
- Calls to `load_from_pk()` and `load_from()` automatically check Memcached first.
- Calling `$record->save()` or `$record->delete()` automatically purges the cached entries for that record.

To manually clear the cache:

```php
// Clear primary key cache
Customer::clear_pk_cache(42);

// Clear query cache for specific criteria
Customer::clear_query_cache(['status' => 'active']);
```

---

## Change History Auditing

When configured via `Bootstrap::init(['changed_by' => ...])`, any updates or deletions made through `DBTable` are automatically logged to the `history` table:

```php
Bootstrap::init([
    'changed_by' => fn () => isset($_SESSION['user_id']) ? 'user_id:' . $_SESSION['user_id'] : 'system',
]);
```

Every updated column records the table name, field changed, record ID, old value, new value, timestamp, and who made the change.

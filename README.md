# yii2-options

[![Tests](https://github.com/pozitronik/yii2-options/actions/workflows/ci.yml/badge.svg)](https://github.com/pozitronik/yii2-options/actions/workflows/ci.yml)
[![Codecov](https://codecov.io/gh/pozitronik/yii2-options/branch/master/graph/badge.svg)](https://codecov.io/gh/pozitronik/yii2-options)
[![Packagist Version](https://img.shields.io/packagist/v/pozitronik/yii2-options)](https://packagist.org/packages/pozitronik/yii2-options)
[![Packagist License](https://img.shields.io/packagist/l/pozitronik/yii2-options)](https://packagist.org/packages/pozitronik/yii2-options)
[![Packagist Downloads](https://img.shields.io/packagist/dt/pozitronik/yii2-options)](https://packagist.org/packages/pozitronik/yii2-options)

Server-side key-value options storage for Yii2 applications.

## Installation

Install via [Composer](http://getcomposer.org/download/):

```bash
composer require pozitronik/yii2-options ^2.0.0
```

## Quick Start

1. Run database migration to create the options table:

```bash
./yii migrate --migrationPath=@vendor/pozitronik/yii2-options/migrations
```

2. Configure the component in your application config:

```php
'components' => [
    'sysoptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
    ],
],
```

3. Use it in your code:

```php
// Set an option
SysOptions::setStatic('app.theme', 'dark');

// Get an option
$theme = SysOptions::getStatic('app.theme', 'light'); // Returns 'dark'

// Delete an option
SysOptions::dropStatic('app.theme');
```

## Configuration

Configure the component with optional parameters:

```php
'components' => [
    'sysoptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
        'tableName' => 'custom_options',      // Default: 'sys_options'
        'cache' => 'cache',                    // Default: 'cache' (set to null to disable)
        'cacheDuration' => 3600,               // Default: null (infinite)
        'allowedClasses' => false,             // Default: true (see Security below)
    ],
],
```

### Configuration Options

| Property                   | Type                           | Default         | Description                                                |
|----------------------------|--------------------------------|-----------------|------------------------------------------------------------|
| `tableName`                | `string`                       | `'sys_options'` | Database table name for storing options                    |
| `cache`                    | `string\|CacheInterface\|null` | `'cache'`       | Cache component ID or instance (null to disable caching)   |
| `cachePrefix`              | `string\|null`                 | `null`          | Cache key prefix for namespace isolation (uses class name) |
| `cacheDuration`            | `int\|null`                    | `null`          | Cache duration in seconds (null = infinite)                |
| `db`                       | `string\|Connection`           | `'db'`          | Database connection component ID or instance               |
| `allowedClasses`           | `bool\|array`                  | `true`          | Classes allowed for deserialization (see Security)         |
| `serializer`               | `array\|null`                  | `null`          | Custom serialization functions                             |
| `legacyCacheCompatibility` | `bool`                         | `false`         | Enable v1.1.0 cache fix during migration (temporary)       |

## Usage

### Instance Methods

```php
$options = Yii::$app->sysoptions;

// Set option
$options->set('user.notifications', true);

// Get option with default fallback
$notifications = $options->get('user.notifications', false);

// Check null vs non-existent
$options->set('explicit.null', null);
$options->get('explicit.null');    // Returns: null (exists in DB)
$options->get('nonexistent');      // Returns: null (doesn't exist)

// Delete option
$options->drop('user.notifications');

// Bulk operations
$all = $options->retrieveOptions();                  // Get all options
$names = $options->getAllNames();                    // Get all option names
$appOptions = $options->getByPattern('app.%');       // Get by SQL LIKE pattern
$options->clear();                                   // Delete all options
```

### Static Methods

For convenience, you can use static methods without accessing the component:

```php
use pozitronik\sys_options\models\SysOptions;

SysOptions::setStatic('config.version', '2.0');
$version = SysOptions::getStatic('config.version');
SysOptions::dropStatic('config.version');
```

**Note:** Static methods require the `sysoptions` component to be configured in your application.

## Data Types

The extension uses PHP serialization by default and supports any serializable data type:

```php
// Scalars
$options->set('string', 'value');
$options->set('integer', 42);
$options->set('float', 3.14);
$options->set('boolean', true);
$options->set('null', null);

// Arrays
$options->set('array', ['key' => 'value', 'nested' => ['data']]);

// Objects (when allowedClasses permits)
$options->set('datetime', new DateTime());
```

### Custom Serialization

You can use custom serialization (e.g., JSON):

```php
$options->serializer = [
    fn($value) => json_encode($value),           // Serialize
    fn(string $value) => json_decode($value),    // Deserialize
];
```

## Security

The `allowedClasses` parameter controls PHP object deserialization security:

```php
// RECOMMENDED: Only primitives (no objects)
'allowedClasses' => false,

// Whitelist specific classes
'allowedClasses' => [stdClass::class, DateTime::class],

// Allow all classes (default for backward compatibility - NOT RECOMMENDED)
'allowedClasses' => true,
```

**Important:** Setting `allowedClasses` to `true` may pose security risks if your database is compromised. See [PHP Object Injection](https://owasp.org/www-community/vulnerabilities/PHP_Object_Injection) for details.

## Caching

The extension uses Yii2's caching with TagDependency for automatic cache invalidation:

```php
'components' => [
    'sysoptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
        'cache' => 'cache',          // Cache component (set to null to disable)
        'cacheDuration' => 3600,     // 1 hour (null = infinite)
    ],
],
```

Cache is automatically invalidated when options are modified.

## Multiple Instances with Separate Cache Namespaces

When using multiple SysOptions instances (e.g., for different tables or applications), you can use `cachePrefix` to prevent cache key collisions:

```php
'components' => [
    // Application options
    'appOptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
        'tableName' => 'app_options',
        'cachePrefix' => 'AppOptions',     // Custom cache prefix
    ],

    // User options
    'userOptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
        'tableName' => 'user_options',
        'cachePrefix' => 'UserOptions',    // Different cache prefix
    ],
],
```

Each instance will use its own cache namespace, preventing interference:
- `AppOptions::get(theme)` → Cache key: `AppOptions::get(theme)`
- `UserOptions::get(theme)` → Cache key: `UserOptions::get(theme)`

Without `cachePrefix`, both would use the same cache key based on the class name, causing cache collisions.

## Migrating from v1.x to v2.x

When upgrading from v1.x to v2.x without flushing cache, you may encounter issues where non-existent options return `null` instead of default values. This is caused by legacy cache entries from v1.1.0.

### Option 1: Enable Legacy Cache Compatibility (Recommended for Zero-Downtime)

Enable the compatibility fix temporarily during migration:

```php
'components' => [
    'sysoptions' => [
        'class' => \pozitronik\sys_options\models\SysOptions::class,
        'legacyCacheCompatibility' => true,  // Enable during migration
    ],
],
```

This will automatically detect and fix v1.1.0 cache entries on first access. Once all legacy cache entries expire (based on your cache TTL) or after a reasonable migration period, disable this option to avoid the extra database query overhead.

### Option 2: Flush Cache (Clean Approach)

If you can afford to flush the cache during deployment:

```bash
# Flush all cache
yii cache/flush-all

# Or flush programmatically
Yii::$app->cache->flush();
```
## License

GNU GPL v3.0

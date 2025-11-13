# yii2-options

[![Build Status](https://github.com/pozitronik/yii2-options/actions/workflows/ci.yml/badge.svg)](https://github.com/pozitronik/yii2-options/actions)

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
        'cacheEnabled' => true,                // Default: true
        'cacheDuration' => 3600,               // Default: null (infinite)
        'cache' => 'cache',                    // Default: 'cache'
        'allowedClasses' => false,             // Default: true (see Security below)
    ],
],
```

### Configuration Options

| Property         | Type                     | Default         | Description                                        |
|------------------|--------------------------|-----------------|----------------------------------------------------|
| `tableName`      | `string`                 | `'sys_options'` | Database table name for storing options            |
| `cacheEnabled`   | `bool`                   | `true`          | Enable/disable caching                             |
| `cacheDuration`  | `int\|null`              | `null`          | Cache duration in seconds (null = infinite)        |
| `cache`          | `string\|CacheInterface` | `'cache'`       | Cache component ID or instance                     |
| `db`             | `string\|Connection`     | `'db'`          | Database connection component ID or instance       |
| `allowedClasses` | `bool\|array`            | `true`          | Classes allowed for deserialization (see Security) |
| `serializer`     | `array\|null`            | `null`          | Custom serialization functions                     |

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
        'cacheEnabled' => true,      // Enable caching
        'cacheDuration' => 3600,     // 1 hour (null = infinite)
    ],
],
```

Cache is automatically invalidated when options are modified.

## License

GNU GPL v3.0

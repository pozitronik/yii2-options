<?php
declare(strict_types = 1);

namespace pozitronik\sys_options\models;

use Exception;
use Throwable;
use Yii;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\validators\StringValidator;

/**
 * Class SysOptions
 * Storage of system settings in DB/cache
 */
class SysOptions extends Component {

	/**
	 * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
	 * After the SysOptions object is created, if you want to change this property, you should only assign it
	 * with a DB connection object.
	 * This can also be a configuration array for creating the object.
	 */
	public Connection|array|string $db = 'db';

	/**
	 * @var CacheInterface|array|string|null the cache component for intermediate caching. Can be:
	 * - string: application component ID (e.g., 'cache', 'redisCache')
	 * - array: configuration array for creating the cache component
	 * - CacheInterface: cache object
	 * - null: caching disabled
	 * After the SysOptions object is created, if you want to change this property, you should only assign it
	 * with a cache object.
	 */
	public CacheInterface|array|string|null $cache = 'cache';

	/**
	 * @var null|array the functions used to serialize and unserialize values. Defaults to null, meaning
	 * using the default PHP `serialize()` and `unserialize()` functions. If you want to use some more efficient
	 * serializer (e.g. [igbinary](https://pecl.php.net/package/igbinary)), you may configure this property with
	 * a two-element array. The first element specifies the serialization function, and the second the deserialization
	 * function.
	 */
	public null|array $serializer = null;

	/**
	 * @var int|null Cache duration for options in seconds. Defaults to null (infinite caching).
	 * Value can be set in module configuration.
	 *
	 * Possible values:
	 * - null (default): infinite caching (current behavior, backward compatibility)
	 * - 0: cache not used (equivalent to $cache = null)
	 * - positive number: cache lifetime in seconds (e.g., 3600 for 1 hour)
	 *
	 * NOTE: cache is also invalidated via TagDependency when set() or drop() is called,
	 * regardless of $cacheDuration value. TTL serves as an additional safety measure.
	 */
	public ?int $cacheDuration = null;

	/**
	 * @var array|bool Defines which classes are allowed for unserialization
	 *
	 * Possible values:
	 * - true (default): ALL classes allowed (current behavior, backward compatibility)
	 * - false: NO classes allowed, only primitive types (string, int, float, array, null).
	 *   Attempting to unserialize an object will create __PHP_Incomplete_Class
	 * - array: Whitelist of specific classes, e.g., [stdClass::class, DateTime::class].
	 *   To work with simple objects, include stdClass in the list
	 *
	 * IMPORTANT: Value true may pose security risks when deserializing untrusted data.
	 * It's recommended to use false or an explicit class whitelist.
	 *
	 * @link https://owasp.org/www-community/vulnerabilities/PHP_Object_Injection
	 */
	public array|bool $allowedClasses = true;

	/**
	 * @var string Database table name for storing options
	 */
	public string $tableName = 'sys_options';

	/**
	 * {@inheritdoc}
	 */
	public function init():void {
		parent::init();
		$this->db = Instance::ensure($this->db, Connection::class);

		// Validate serializer configuration
		if (null !== $this->serializer) {
			if (!is_array($this->serializer)) {
				throw new Exception('Serializer must be an array with two callable elements');
			}
			if (!isset($this->serializer[0], $this->serializer[1])) {
				throw new Exception('Serializer must have both serialize (index 0) and unserialize (index 1) functions');
			}
			if (!is_callable($this->serializer[0]) || !is_callable($this->serializer[1])) {
				throw new Exception('Both serializer elements must be callable');
			}
		}

		// Validate allowedClasses configuration
		if (!is_bool($this->allowedClasses) && !is_array($this->allowedClasses)) {
			throw new Exception('allowedClasses must be either a boolean or an array of class names');
		}

		if (is_array($this->allowedClasses)) {
			foreach ($this->allowedClasses as $className) {
				if (!is_string($className)) {
					throw new Exception('All elements in allowedClasses array must be valid class name strings');
				}
			}
		}

		// Validate cacheDuration configuration
		if (null !== $this->cacheDuration && (!is_int($this->cacheDuration) || $this->cacheDuration < 0)) {
			throw new Exception('cacheDuration must be null or a non-negative integer (seconds)');
		}

		// Resolve cache component
		if (null !== $this->cache) {
			try {
				$this->cache = Instance::ensure($this->cache, CacheInterface::class);
			} catch (Throwable $e) {
				Yii::warning("Failed to resolve cache component: {$e->getMessage()}. Caching disabled.", __METHOD__);
				$this->cache = null;
			}
		}
	}

	/**
	 * Validates option name against length constraints (1-256 characters)
	 * @param string $option The option name to validate
	 * @return void
	 * @throws Exception If validation fails
	 */
	private function validateOptionName(string $option):void {
		$validator = new StringValidator([
			'min' => 1,
			'max' => 256,
			'tooShort' => 'Option name cannot be empty',
			'tooLong' => 'Option name cannot exceed 256 characters',
		]);

		$error = '';
		if (!$validator->validate($option, $error)) {
			throw new Exception($error);
		}
	}

	/**
	 * Serializes a value for storage in the database
	 * @param mixed $value The value to serialize
	 * @return string The serialized value
	 */
	protected function serialize(mixed $value):string {
		return (null === $this->serializer)?serialize($value):call_user_func($this->serializer[0], $value);
	}

	/**
	 * Unserializes a value retrieved from the database
	 *
	 * Uses $allowedClasses configuration to control deserialization security.
	 *
	 * @param string $value The serialized value
	 * @return mixed The unserialized value
	 * @throws Exception If unserialization failed or contains disallowed classes
	 * @see $allowedClasses
	 */
	protected function unserialize(string $value):mixed {
		if (null !== $this->serializer) {
			return call_user_func($this->serializer[1], $value);
		}

		try {
			$result = unserialize($value, ['allowed_classes' => $this->allowedClasses]);
		} catch (Throwable $e) {
			// Catch exceptions thrown by error handlers (e.g., Yii's ErrorHandler converting E_NOTICE to ErrorException)
			throw new Exception("Failed to unserialize value: {$e->getMessage()}. Data may be corrupted or contain disallowed classes.");
		}

		if (false === $result && 'b:0;' !== $value) {
			// Check if there was an error during unserialization (when no exception was thrown)
			$error = error_get_last();
			if ($error && (E_NOTICE === $error['type'] || E_WARNING === $error['type'])) {
				throw new Exception("Failed to unserialize value: {$error['message']}. Data may be corrupted or contain disallowed classes.");
			}

			throw new Exception('Failed to unserialize value. Data may be corrupted or contain disallowed classes.');
		}

		return $result;
	}

	/**
	 * Retrieves a serialized option value from the database
	 * @param string $option The option name to retrieve
	 * @return string|null The serialized option value or null if option doesn't exist
	 */
	protected function retrieveDbValue(string $option):?string {
		try {
			$row = new Query()
				->noCache()
				->select('value')
				->from($this->tableName)
				->where(['option' => $option])
				->one();

			if (false !== $row) {
				return self::getRowValue($row);
			}
		} catch (Throwable $e) {
			Yii::warning("Unable to retrieve option value from database: {$e->getMessage()}", __METHOD__);
		}
		return null;
	}

	/**
	 * Inserts or updates an option value in the database
	 * @param string $option The option name
	 * @param string $value The serialized value to store
	 * @return bool True on success, false on database error
	 */
	protected function applyDbValue(string $option, string $value):bool {
		try {
			return $this->db->noCache(function(Connection $db) use ($option, $value) {
				$db->createCommand()->upsert($this->tableName, compact('option', 'value'))->execute();
				return true;
			});
		} catch (Throwable $e) {
			Yii::warning("Unable to update or insert table value: {$e->getMessage()}", __METHOD__);
		}
		return false;
	}

	/**
	 * Deletes an option from the database
	 * @param string $option The option name to delete
	 * @return bool True on success, false on database error
	 */
	protected function removeDbValue(string $option):bool {
		try {
			return $this->db->noCache(function(Connection $db) use ($option) {
				$db->createCommand()->delete($this->tableName, compact('option'))->execute();
				return true;
			});
		} catch (Throwable $e) {
			Yii::warning("Unable to remove table value: {$e->getMessage()}", __METHOD__);
		}
		return false;
	}

	/**
	 * Retrieves an option value from the database (with caching if enabled)
	 * @param string $option The option name to retrieve
	 * @param mixed $default Default value to return if option doesn't exist (null by default)
	 * @return mixed The option value or default if option doesn't exist
	 * @throws Exception If option name validation fails
	 */
	public function get(string $option, mixed $default = null):mixed {
		$this->validateOptionName($option);

		$cacheKey = static::class."::get({$option})";

		if (null !== $this->cache) { // Try to get from cache first, else retrieve from DB
			if ((false === $dbValue = $this->cache->get($cacheKey)) && null !== $dbValue = $this->retrieveDbValue($option)) {
				$this->cache->set($cacheKey, $dbValue, $this->cacheDuration, new TagDependency(['tags' => static::class."::get({$option})"]));
			}
		} else { // No cache - retrieve directly
			$dbValue = $this->retrieveDbValue($option);
		}

		return (null === $dbValue)
			?$default // If option doesn't exist in database, return default value
			:$this->unserialize($dbValue); // Option exists, return its value (even if it's null)
	}

	/**
	 * Stores an option value in the database and invalidates cache
	 * @param string $option The option name to store
	 * @param mixed $value The value to store (will be serialized)
	 * @return bool True on success, false on database error
	 * @throws Exception If option name validation fails
	 */
	public function set(string $option, mixed $value):bool {
		$this->validateOptionName($option);
		$result = $this->applyDbValue($option, $this->serialize($value));
		if ($result && null !== $this->cache) {
			TagDependency::invalidate($this->cache, [static::class."::get({$option})"]);
		}
		return $result;
	}

	/**
	 * Deletes an option from the database and invalidates cache
	 * @param string $option The option name to delete
	 * @return bool True on success, false on database error
	 * @throws Exception If option name validation fails
	 */
	public function drop(string $option):bool {
		$this->validateOptionName($option);
		$result = $this->removeDbValue($option);
		if ($result && null !== $this->cache) {
			TagDependency::invalidate($this->cache, [static::class."::get({$option})"]);
		}
		return $result;
	}

	/**
	 * Retrieves options from database based on WHERE condition
	 *
	 * @param array|null $condition WHERE condition for Query (null = all records)
	 * @return array Associative array of options ['option_name' => value]
	 */
	public function retrieveOptions(?array $condition = null):array {
		try {
			$query = new Query()
				->noCache()
				->select(['option', 'value'])
				->from($this->tableName);

			if (null !== $condition) {
				$query->where($condition);
			}

			$rows = $query->all();

			$result = [];
			foreach ($rows as $row) {
				$result[$row['option']] = $this->unserialize(self::getRowValue($row));
			}

			return $result;
		} catch (Throwable $e) {
			Yii::warning("Unable to retrieve options from database: {$e->getMessage()}", __METHOD__);
			return [];
		}
	}

	/**
	 * Extracts value from database row, handling PostgreSQL resource stream
	 *
	 * @param array $row Database row with 'value' key
	 * @return string Serialized value content
	 * @throws Exception
	 */
	private static function getRowValue(array $row):string {
		$value = $row['value'];
		if (is_resource($value) && 'stream' === get_resource_type($value)) {
			// PDO manages these resources internally, they're automatically freed when the result set is destroyed
			$value = stream_get_contents($value, null, 0);
		}
		return $value;
	}

	/**
	 * Retrieves all option names
	 *
	 * Returns list of all option names stored in database.
	 *
	 * @return array Array of option names
	 */
	public function getAllNames():array {
		try {
			return new Query()
				->noCache()
				->select('option')
				->from($this->tableName)
				->column();
		} catch (Throwable $e) {
			Yii::warning("Unable to retrieve option names from database: {$e->getMessage()}", __METHOD__);
			return [];
		}
	}

	/**
	 * Retrieves options matching SQL LIKE pattern
	 *
	 * Returns options where name matches the SQL LIKE pattern.
	 *
	 * Pattern examples:
	 * - 'app.%' - matches 'app.cache', 'app.debug', etc.
	 * - '%config%' - matches 'app.config', 'db.config', 'user_config', etc.
	 * - 'cache_' - matches 'cache_1', 'cache_2', etc. (single character)
	 *
	 * NOTE: This method does not use cache and reads directly from database.
	 *
	 * @param string $pattern SQL LIKE pattern (use % for wildcard, _ for single char)
	 * @return array Associative array of matching options ['option_name' => value]
	 */
	public function getByPattern(string $pattern):array {
		return $this->retrieveOptions(['like', 'option', $pattern, false]);
	}

	/**
	 * Deletes all options from database and invalidates cache
	 *
	 * WARNING: This operation cannot be undone!
	 *
	 * Cache invalidation: When cache is configured, this method retrieves all option names before deletion
	 * and invalidates only corresponding cache entries via TagDependency. This ensures that only
	 * SysOptions cache entries are cleared, without affecting data from other components.
	 *
	 * @return bool True on success, false on database error or error retrieving option names
	 */
	public function clear():bool {
		try {
			// Get all option names before deletion for cache invalidation
			$optionNames = $this->getAllNames();

			// Delete all records from database
			$result = $this->db->noCache(function(Connection $db) {
				$db->createCommand()->delete($this->tableName)->execute();
				return true;
			});

			// Invalidate only cache for deleted options (not entire cache)
			if ($result && null !== $this->cache && !empty($optionNames)) {
				$tags = array_map(
					static fn(string $option):string => static::class."::get({$option})",
					$optionNames
				);
				TagDependency::invalidate($this->cache, $tags);
			}

			return $result;
		} catch (Throwable $e) {
			Yii::warning("Unable to clear all options: {$e->getMessage()}", __METHOD__);
		}
		return false;
	}

	/**
	 * Gets SysOptions instance from service locator
	 *
	 * Requires 'sysoptions' component to be configured in application config.
	 *
	 * @return self The SysOptions instance
	 * @throws Exception If 'sysoptions' component is not configured
	 */
	private static function getServiceInstance():self {
		if (!Yii::$app->has('sysoptions')) {
			throw new Exception(
				'SysOptions component is not configured. Add to application config: ' .
				"'components' => ['sysoptions' => ['class' => SysOptions::class]]"
			);
		}
		return Yii::$app->get('sysoptions');
	}

	/**
	 * Static call with the same logic as get() (for backward compatibility)
	 *
	 * Requires 'sysoptions' component configured in application:
	 * 'components' => ['sysoptions' => ['class' => SysOptions::class]]
	 *
	 * @param string $option The option name
	 * @param mixed $default Default value to return if option doesn't exist (null by default)
	 * @return mixed The option value or default if option doesn't exist
	 * @throws Exception If 'sysoptions' component is not configured
	 * @throws Throwable
	 * @deprecated Have to be removed in the next versions
	 */
	public static function getStatic(string $option, mixed $default = null):mixed {
		return self::getServiceInstance()->get($option, $default);
	}

	/**
	 * Static call with the same logic as set() (for backward compatibility)
	 *
	 * Requires 'sysoptions' component configured in application:
	 * 'components' => ['sysoptions' => ['class' => SysOptions::class]]
	 *
	 * @param string $option The option name
	 * @param mixed $value The value to store (will be serialized)
	 * @return bool True on success, false on failure
	 * @throws Exception If 'sysoptions' component is not configured
	 * @deprecated Have to be removed in the next versions
	 */
	public static function setStatic(string $option, mixed $value):bool {
		return self::getServiceInstance()->set($option, $value);
	}

	/**
	 * Static call with the same logic as drop() (for backward compatibility)
	 *
	 * Requires 'sysoptions' component configured in application:
	 * 'components' => ['sysoptions' => ['class' => SysOptions::class]]
	 *
	 * @param string $option The option name to delete
	 * @return bool True on success, false on failure
	 * @throws Exception If 'sysoptions' component is not configured
	 * @deprecated Have to be removed in the next versions
	 */
	public static function dropStatic(string $option):bool {
		return self::getServiceInstance()->drop($option);
	}

}

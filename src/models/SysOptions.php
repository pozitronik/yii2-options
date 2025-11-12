<?php
declare(strict_types = 1);

namespace pozitronik\sys_options\models;

use Exception;
use Throwable;
use Yii;
use yii\base\Model;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\validators\StringValidator;

/**
 * Class SysOptions
 * Storage of system settings in DB/cache
 */
class SysOptions extends Model {

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
	 * - null: use application default cache (Yii::$app->cache), to explicitly disable set $cacheEnabled = false
	 * After the SysOptions object is created, if you want to change this property, you should only assign it
	 * with a cache object.
	 */
	public CacheInterface|array|string|null $cache = null;

	/**
	 * @var null|array the functions used to serialize and unserialize values. Defaults to null, meaning
	 * using the default PHP `serialize()` and `unserialize()` functions. If you want to use some more efficient
	 * serializer (e.g. [igbinary](https://pecl.php.net/package/igbinary)), you may configure this property with
	 * a two-element array. The first element specifies the serialization function, and the second the deserialization
	 * function.
	 */
	public null|array $serializer = null;

	/**
	 * @var bool enable intermediate caching. Default value can be set in module configuration.
	 * If $cache is not configured or null, caching will be automatically disabled regardless of this parameter.
	 */
	public bool $cacheEnabled = true;

	private string $_tableName = 'sys_options';

	/**
	 * {@inheritdoc}
	 */
	public function init():void {
		parent::init();
		$this->db = Instance::ensure($this->db, Connection::class);
		$this->_tableName = ArrayHelper::getValue(Yii::$app->modules, 'sysoptions.params.tableName', $this->_tableName);
		$this->cacheEnabled = ArrayHelper::getValue(Yii::$app->modules, 'sysoptions.params.cacheEnabled', $this->cacheEnabled);

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

		// Resolve cache component
		if (null === $this->cache) {
			// Backward compatibility: if cache is not specified, use application default cache
			$this->cache = Yii::$app->cache;
		} else {
			// If explicitly specified, resolve via Instance::ensure
			try {
				$this->cache = Instance::ensure($this->cache, CacheInterface::class);
			} catch (Throwable $e) {
				Yii::warning("Failed to resolve cache component: {$e->getMessage()}. Caching disabled.", __METHOD__);
				$this->cache = null;
				$this->cacheEnabled = false;
			}
		}

		// If caching is enabled but cache component is missing - disable caching
		if ($this->cacheEnabled && null === $this->cache) {
			Yii::warning('Caching is enabled but cache component is not configured. Caching disabled.', __METHOD__);
			$this->cacheEnabled = false;
		}
	}

	/**
	 * Returns the name of the table used for storing options
	 * @return string
	 */
	public function getTableName():string {
		return $this->_tableName;
	}

	/**
	 * Validates option name
	 * @param string $option
	 * @return void
	 * @throws Exception
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
	 * @param mixed $value
	 * @return string
	 */
	protected function serialize(mixed $value):string {
		return (null === $this->serializer)?serialize($value):call_user_func($this->serializer[0], $value);
	}

	/**
	 * @param string $value
	 * @return mixed
	 */
	protected function unserialize(string $value) {
		return (null === $this->serializer)?unserialize($value, ['allowed_classes' => true]):call_user_func($this->serializer[1], $value);
	}

	/**
	 * @param string $option
	 * @return string
	 * @throws Exception
	 */
	protected function retrieveDbValue(string $option):string {
		$value = ArrayHelper::getValue((new Query())->noCache()->select('value')->from($this->_tableName)->where(['option' => $option])->one(), 'value', $this->serialize(null));
		if (is_resource($value) && 'stream' === get_resource_type($value)) {
			$result = stream_get_contents($value);
			fseek($value, 0);
			return $result;
		}
		return $value;
	}

	/**
	 * @param string $option
	 * @param string $value
	 * @return bool
	 */
	protected function applyDbValue(string $option, string $value):bool {
		try {
			return $this->db->noCache(function(Connection $db) use ($option, $value) {
				$db->createCommand()->upsert($this->_tableName, compact('option', 'value'))->execute();
				return true;
			});
		} catch (Throwable $e) {
			Yii::warning("Unable to update or insert table value: {$e->getMessage()}", __METHOD__);
		}
		return false;
	}

	/**
	 * @param string $option
	 * @return bool
	 */
	protected function removeDbValue(string $option):bool {
		try {
			return $this->db->noCache(function(Connection $db) use ($option) {
				$db->createCommand()->delete($this->_tableName, compact('option'))->execute();
				return true;
			});
		} catch (Throwable $e) {
			Yii::warning("Unable to remove table value: {$e->getMessage()}", __METHOD__);
		}
		return false;
	}

	/**
	 * @param string $option
	 * @param mixed $default
	 * @return mixed|null (null by default)
	 * @throws Exception
	 */
	public function get(string $option, mixed $default = null):mixed {
		$this->validateOptionName($option);
		$dbValue = ($this->cacheEnabled && $this->cache)
			?$this->cache->getOrSet(
				static::class."::get({$option})",
				fn() => $this->retrieveDbValue($option),
				null,
				new TagDependency(['tags' => static::class."::get({$option})"])
			)
			:$this->retrieveDbValue($option);
		return (null === $value = $this->unserialize($dbValue))?$default:$value;
	}

	/**
	 * @param string $option
	 * @param mixed $value
	 * @return bool
	 * @throws Exception
	 */
	public function set(string $option, mixed $value):bool {
		$this->validateOptionName($option);
		if ($this->cacheEnabled && $this->cache) {
			TagDependency::invalidate($this->cache, [static::class."::get({$option})"]);
		}
		return $this->applyDbValue($option, $this->serialize($value));
	}

	/**
	 * @param string $option
	 * @return bool
	 * @throws Exception
	 */
	public function drop(string $option):bool {
		$this->validateOptionName($option);
		if ($this->cacheEnabled && $this->cache) {
			TagDependency::invalidate($this->cache, [static::class."::get({$option})"]);
		}
		return $this->removeDbValue($option);
	}

	/**
	 * Static call with the same logic as get()
	 * @param string $option
	 * @param null $default
	 * @return mixed (null by default)
	 * @throws Throwable
	 */
	public static function getStatic(string $option, mixed $default = null):mixed {
		return (new self())->get($option, $default);
	}

	/**
	 * Static call with the same logic as set()
	 * @param string $option
	 * @param mixed $value
	 * @return bool
	 * @throws Exception
	 */
	public static function setStatic(string $option, mixed $value):bool {
		return (new self())->set($option, $value);
	}

	/**
	 * Static call with the same logic as drop()
	 * @param string $option
	 * @return bool
	 * @throws Exception
	 */
	public static function dropStatic(string $option):bool {
		return (new self())->drop($option);
	}

}

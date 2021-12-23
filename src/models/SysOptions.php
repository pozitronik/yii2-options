<?php
/**
 * @noinspection MissingPropertyAnnotationsInspection
 * Из-за методов get/set. Здесь всё корректно, я специально дал им такие имена, они не конфликтуют с геттерами/сеттерами
 */
declare(strict_types = 1);

namespace pozitronik\sys_options\models;

use Exception;
use Throwable;
use Yii;
use yii\base\Model;
use yii\caching\TagDependency;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\helpers\ArrayHelper;

/**
 * Class SysOptions
 * Хранение системных настроек в БД/кеше
 */
class SysOptions extends Model {

	/**
	 * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
	 * After the SysOptions object is created, if you want to change this property, you should only assign it
	 * with a DB connection object.
	 * This can also be a configuration array for creating the object.
	 */
	public $db = 'db';

	/**
	 * @var null|array the functions used to serialize and unserialize values. Defaults to null, meaning
	 * using the default PHP `serialize()` and `unserialize()` functions. If you want to use some more efficient
	 * serializer (e.g. [igbinary](https://pecl.php.net/package/igbinary)), you may configure this property with
	 * a two-element array. The first element specifies the serialization function, and the second the deserialization
	 * function.
	 */
	public $serializer;
	/**
	 * @var bool enable intermediate caching via Yii::$app->cache (must be configured in framework). Default option
	 * value can be set in module configuration, e.g.
	 * ...
	 * 'sysoptions' => [
	 *        'class' => UsersOptionsModule::class,
	 *            'params' => [
	 *                'cacheEnabled' => true//defaults to false
	 *            ]
	 *        ],
	 * ...
	 */
	public $cacheEnabled = true;

	private $_tableName = 'sys_options';

	/**
	 * {@inheritdoc}
	 */
	public function init():void {
		parent::init();
		$this->db = Instance::ensure($this->db, Connection::class);
		$this->_tableName = ArrayHelper::getValue(Yii::$app->modules, 'sysoptions.params.tableName', $this->_tableName);
		$this->cacheEnabled = ArrayHelper::getValue(Yii::$app->modules, 'sysoptions.params.cacheEnabled', $this->cacheEnabled);
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	protected function serialize($value):string {
		return (null === $this->serializer)?serialize($value):call_user_func($this->serializer[0], $value);
	}

	/**
	 * @param string $value
	 * @return mixed
	 */
	protected function unserialize(string $value) {
		return (null === $this->serializer)
			?unserialize($value, ['allowed_classes' => true])
			:call_user_func($this->serializer[1], $value);
	}

	/**
	 * @param string $option
	 * @return string
	 * @throws Exception
	 */
	protected function retrieveDbValue(string $option):string {
		$value = ArrayHelper::getValue((new Query())->select('value')->from($this->_tableName)->where(['option' => $option])->one(), 'value', serialize(null));
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
	 * @param null $default
	 * @return mixed|null (null by default)
	 * @throws Exception
	 */
	public function get(string $option, $default = null) {
		$dbValue = ($this->cacheEnabled)
			?Yii::$app->cache->getOrSet(static::class."::get({$option})", fn() => $this->retrieveDbValue($option), null, new TagDependency(['tags' => static::class."::get({$option})"]))
			:$this->retrieveDbValue($option);
		return (null === $value = $this->unserialize($dbValue))?$default:$value;
	}

	/**
	 * @param string $option
	 * @param mixed $value
	 * @return bool
	 */
	public function set(string $option, $value):bool {
		TagDependency::invalidate(Yii::$app->cache, [static::class."::get({$option})"]);
		return $this->applyDbValue($option, $this->serialize($value));
	}

	/**
	 * Статический вызов с той же логикой, что у get()
	 * @param string $option
	 * @param null $default
	 * @return mixed|false (null by default)
	 * @throws Throwable
	 */
	public static function getStatic(string $option, $default = null) {
		return (new self())->get($option, $default);
	}

	/**
	 * Статический вызов с той же логикой, что у set()
	 * @param string $option
	 * @param mixed $value
	 * @return bool
	 */
	public static function setStatic(string $option, $value):bool {
		return (new self())->set($option, $value);
	}

}
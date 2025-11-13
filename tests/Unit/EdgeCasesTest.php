<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use pozitronik\sys_options\SysOptionsModule;
use stdClass;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use Yii;
use yii\base\Exception as BaseException;
use yii\base\InvalidRouteException;
use yii\caching\FileCache;
use yii\console\Exception as ConsoleExceptions;
use yii\db\Exception as DbException;

/**
 * Тесты для граничных случаев и выявленных проблем
 */
class EdgeCasesTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Тест Issue #3: Проверка хранения явного null значения
	 * Проблема: невозможно отличить "опции не существует" от "опция установлена в null"
	 * @return void
	 * @throws Exception
	 */
	public function testExplicitNullValueStorage():void {
		$options = new SysOptions();

		// Устанавливаем явный null
		static::assertTrue($options->set('null_option', null));

		// Получаем значение - должно вернуть null, а не default
		$value = $options->get('null_option', 'default_value');

		// Ожидаем, что вернётся null, а не 'default_value'
		static::assertNull($value, 'Явно установленный null должен возвращаться как null');

		// Проверяем несуществующую опцию - должна вернуть default
		$nonExistent = $options->get('non_existent_option', 'default_value');
		static::assertEquals('default_value', $nonExistent, 'Несуществующая опция должна возвращать default');
	}

	/**
	 * Тест Issue #3: Проверка различия между null и несуществующей опцией
	 * @return void
	 * @throws Throwable
	 */
	public function testNullVsNonExistentOption():void {
		$options = new SysOptions();

		// Устанавливаем null
		$options->set('explicit_null', null);

		// Проверяем, что можно отличить null от несуществующей опции
		$nullValue = $options->get('explicit_null', 'default_for_null');
		$nonExistent = $options->get('never_set', 'default_for_nonexistent');

		// Оба возвращают default - это проблема!
		// После фикса: null должен возвращать null, а несуществующая - default
		static::assertNotEquals($nullValue, $nonExistent,
			'Явно установленный null должен отличаться от несуществующей опции');
	}

	/**
	 * Тест Issue #2: Проверка корректной инвалидации кеша
	 * Проблема: кеш инвалидируется ДО записи в БД, что может привести к inconsistent state
	 * @return void
	 * @throws BaseException
	 */
	public function testCacheInvalidationTiming():void {
		// Настраиваем реальный кеш вместо DummyCache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Устанавливаем значение
		$options->set('cached_option', 'initial_value');

		// Читаем значение (закешируется)
		$value1 = $options->get('cached_option');
		static::assertEquals('initial_value', $value1);

		// Обновляем значение
		$options->set('cached_option', 'updated_value');

		// Читаем снова - должно вернуть обновлённое значение из БД, а не из кеша
		$value2 = $options->get('cached_option');
		static::assertEquals('updated_value', $value2,
			'После обновления должно возвращаться новое значение, а не закешированное старое');
	}

	/**
	 * Тест Issue #4: Проверка работы при отсутствующем кеше
	 * Проблема: нет проверки на null для Yii::$app->cache
	 * @return void
	 * @throws BaseException
	 */
	public function testMissingCacheComponent():void {
		// Удаляем компонент кеша
		Yii::$app->set('cache', null);

		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Эти операции не должны выбрасывать исключения
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));
		static::assertTrue($options->drop('test_option'));
	}

	/**
	 * Тест Issue #5: Проверка валидации кастомного сериализатора - невалидная конфигурация
	 * Проблема: нет валидации, что $serializer это массив с индексами 0 и 1
	 * Note: With PHP 8.4 strict typing, we can't assign non-array to $serializer property,
	 * so this test validates that empty array is rejected
	 * @return void
	 */
	public function testInvalidSerializerConfiguration():void {
		$this->expectException(Throwable::class);

		// Empty array - invalid configuration
		new SysOptions(['serializer' => []]);
	}

	/**
	 * Тест Issue #5: Проверка валидации сериализатора - массив без нужных индексов
	 * @return void
	 */
	public function testSerializerMissingIndices():void {
		$this->expectException(Throwable::class);

		// Массив без индекса 0
		new SysOptions(['serializer' => [1 => fn(string $v) => json_decode($v, true)]]);
	}

	/**
	 * Тест Issue #5: Проверка валидации сериализатора - некорректные callable
	 * @return void
	 */
	public function testSerializerNotCallable():void {
		$this->expectException(Throwable::class);

		// Не callable значения
		new SysOptions(['serializer' => [0 => 'not_callable', 1 => 'also_not_callable']]);
	}

	/**
	 * Тест: Проверка работы с кастомным сериализатором (JSON)
	 * @return void
	 * @throws Exception
	 */
	public function testCustomJsonSerializer():void {
		$options = new SysOptions();
		$options->serializer = [
			0 => fn($value) => json_encode($value),
			1 => fn(string $value) => json_decode($value, true),
		];

		$testData = ['key' => 'value', 'number' => 42];
		static::assertTrue($options->set('json_option', $testData));

		$retrieved = $options->get('json_option');
		static::assertEquals($testData, $retrieved);
	}

	/**
	 * Тест Issue #7: Проверка валидации пустого имени опции
	 * Проблема: нет валидации имени опции
	 * @return void
	 * @throws Exception
	 */
	public function testEmptyOptionName():void {
		$options = new SysOptions();

		// Пустая строка не должна быть валидным именем
		$this->expectException(Throwable::class);
		$options->set('', 'value');
	}

	/**
	 * Тест Issue #7: Проверка валидации слишком длинного имени опции
	 * Проблема: имена >256 символов не валидируются
	 * @return void
	 * @throws Exception
	 */
	public function testTooLongOptionName():void {
		$options = new SysOptions();

		// Генерируем имя длиной 257 символов
		$longName = str_repeat('a', 257);

		// Должно выбросить исключение или вернуть false
		$this->expectException(Throwable::class);
		$options->set($longName, 'value');
	}

	/**
	 * Тест Issue #7: Проверка специальных символов в имени опции
	 * @return void
	 * @throws Exception
	 */
	public function testSpecialCharactersInOptionName():void {
		$options = new SysOptions();

		// Тестируем различные специальные символы
		$specialNames = [
			'option.with.dots',
			'option-with-dashes',
			'option_with_underscores',
			'option:with:colons',
			'option/with/slashes',
			'опция_на_русском',
		];

		foreach ($specialNames as $name) {
			static::assertTrue($options->set($name, 'test_value'),
				"Должна поддерживаться опция с именем: {$name}");
			static::assertEquals('test_value', $options->get($name));
			static::assertTrue($options->drop($name));
		}
	}

	/**
	 * Тест Issue #7: Проверка SQL-инъекции в имени опции
	 * @return void
	 * @throws Exception
	 */
	public function testSqlInjectionInOptionName():void {
		$options = new SysOptions();

		// Потенциально опасное имя
		$dangerousName = "'; DROP TABLE sys_options; --";

		// Должно безопасно обработаться
		static::assertTrue($options->set($dangerousName, 'test_value'));
		static::assertEquals('test_value', $options->get($dangerousName));
		static::assertTrue($options->drop($dangerousName));

		// Проверяем, что таблица всё ещё существует
		$options->set('check', 'table_exists');
		static::assertEquals('table_exists', $options->get('check'));
	}

	/**
	 * Тест: Проверка поведения upsert (обновление существующего значения)
	 * @return void
	 * @throws BaseException
	 */
	public function testUpsertBehavior():void {
		$options = new SysOptions();

		// Создаём опцию
		static::assertTrue($options->set('upsert_test', 'initial_value'));
		static::assertEquals('initial_value', $options->get('upsert_test'));

		// Обновляем ту же опцию
		static::assertTrue($options->set('upsert_test', 'updated_value'));
		static::assertEquals('updated_value', $options->get('upsert_test'));

		// Проверяем, что в БД только одна запись (UPDATE, а не INSERT)
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'upsert_test']
		)->queryScalar();

		static::assertEquals(1, $count, 'Должна быть только одна запись (UPDATE вместо INSERT)');
	}

	/**
	 * Тест: Проверка конфигурации кастомного имени таблицы
	 * @return void
	 * @throws BaseException
	 * @throws Throwable
	 */
	public function testCustomTableNameConfiguration():void {
		// Создаём кастомную таблицу
		$customTableName = 'custom_options_table';
		Yii::$app->db->createCommand()->createTable($customTableName, [
			'id' => 'pk',
			'option' => 'string(256) NOT NULL',
			'value' => 'binary NULL',
		])->execute();

		Yii::$app->db->createCommand()->createIndex(
			'idx_custom_option',
			$customTableName,
			'option',
			true
		)->execute();

		// Конфигурируем модуль с кастомным именем таблицы
		Yii::$app->setModule('sysoptions', [
			'class' => SysOptionsModule::class,
			'params' => [
				'tableName' => $customTableName,
			]
		]);

		// Создаём новый экземпляр - он должен использовать кастомную таблицу
		$options = new SysOptions();

		static::assertTrue($options->set('custom_table_test', 'test_value'));
		static::assertEquals('test_value', $options->get('custom_table_test'));

		// Проверяем, что данные в кастомной таблице
		$value = Yii::$app->db->createCommand(
			"SELECT value FROM {$customTableName} WHERE option = :option",
			[':option' => 'custom_table_test']
		)->queryScalar();

		static::assertNotFalse($value, 'Данные должны быть в кастомной таблице');

		// Очистка
		Yii::$app->db->createCommand()->dropTable($customTableName)->execute();
	}

	/**
	 * Тест: Проверка конфигурации отключения кеша через модуль
	 * @return void
	 * @throws BaseException
	 */
	public function testCacheDisabledViaConfiguration():void {
		// Настраиваем реальный кеш
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Конфигурируем модуль с отключенным кешем
		Yii::$app->setModule('sysoptions', [
			'class' => SysOptionsModule::class,
			'params' => [
				'cacheEnabled' => false,
			]
		]);

		$options = new SysOptions();

		// cacheEnabled должен быть false из конфигурации модуля
		static::assertFalse($options->cacheEnabled,
			'cacheEnabled должен быть false из конфигурации модуля');

		static::assertTrue($options->set('no_cache_test', 'test_value'));
		static::assertEquals('test_value', $options->get('no_cache_test'));
	}

	/**
	 * Тест: Проверка сериализации объектов
	 * @return void
	 * @throws Exception
	 */
	public function testObjectSerialization():void {
		$options = new SysOptions();

		$object = new stdClass();
		$object->property = 'value';
		$object->number = 42;

		static::assertTrue($options->set('object_option', $object));

		$retrieved = $options->get('object_option');
		static::assertInstanceOf(stdClass::class, $retrieved);
		static::assertEquals('value', $retrieved->property);
		static::assertEquals(42, $retrieved->number);
	}

	/**
	 * Тест: Проверка хранения больших данных
	 * @return void
	 * @throws Exception
	 */
	public function testLargeDataStorage():void {
		$options = new SysOptions();

		// Генерируем большой массив данных (~1MB)
		$largeData = array_fill(0, 10000, str_repeat('x', 100));

		static::assertTrue($options->set('large_data', $largeData));

		$retrieved = $options->get('large_data');
		static::assertEquals($largeData, $retrieved);
		static::assertCount(10000, $retrieved);
	}

	/**
	 * Тест: Проверка хранения различных типов данных
	 * @return void
	 * @throws Exception
	 */
	public function testVariousDataTypes():void {
		$options = new SysOptions();

		$testCases = [
			'boolean_true' => true,
			'boolean_false' => false,
			'zero_integer' => 0,
			'negative_integer' => -42,
			'zero_float' => 0.0,
			'negative_float' => -3.14,
			'empty_string' => '',
			'empty_array' => [],
			'nested_array' => ['a' => ['b' => ['c' => 'deep']]],
		];

		foreach ($testCases as $key => $value) {
			static::assertTrue($options->set($key, $value), "Не удалось сохранить: {$key}");
			$retrieved = $options->get($key);
			static::assertSame($value, $retrieved, "Некорректное значение для: {$key}");
		}
	}

	/**
	 * Тест Issue #8: Проверка обработки ошибок при чтении из БД
	 * Проблема: нет try-catch в retrieveDbValue
	 * @return void
	 * @throws ConsoleExceptions
	 * @throws InvalidRouteException
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseRead():void {
		$options = new SysOptions();

		// Временно удаляем таблицу для симуляции ошибки
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Должно обработать ошибку, а не выбросить исключение
		$result = $options->get('any_option', 'default_value');

		// После фикса: должно вернуть default или null, а не выбросить исключение
		static::assertEquals('default_value', $result,
			'При ошибке БД должно возвращаться default значение');

		// Восстанавливаем таблицу
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Тест: Проверка обработки ошибок при записи в БД
	 * @return void
	 * @throws ConsoleExceptions
	 * @throws InvalidRouteException
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseWrite():void {
		$options = new SysOptions();

		// Сохраняем значение для проверки
		$options->set('test_before_drop', 'value');

		// Удаляем таблицу
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Должно вернуть false, а не выбросить исключение
		$result = $options->set('test_option', 'test_value');
		static::assertFalse($result, 'При ошибке БД set() должен возвращать false');

		// Восстанавливаем таблицу
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Тест: Проверка обработки ошибок при удалении из БД
	 * @return void
	 * @throws ConsoleExceptions
	 * @throws InvalidRouteException
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseDelete():void {
		$options = new SysOptions();

		// Удаляем таблицу
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Должно вернуть false, а не выбросить исключение
		$result = $options->drop('test_option');
		static::assertFalse($result, 'При ошибке БД drop() должен возвращать false');

		// Восстанавливаем таблицу
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Тест Issue #11: Проверка доступа к имени таблицы
	 * Проблема: $_tableName приватная, нет getter
	 * @return void
	 */
	public function testTableNameAccessibility():void {
		$options = new SysOptions();

		// Должен быть публичный getter для имени таблицы
		$tableName = $options->getTableName();
		static::assertEquals('sys_options', $tableName,
			'Должен быть доступ к имени используемой таблицы');
	}

	/**
	 * Тест: Проверка concurrent доступа (множественные операции)
	 * @return void
	 * @throws BaseException
	 */
	public function testConcurrentOperations():void {
		$options = new SysOptions();

		// Симулируем множественные операции с одной опцией
		$options->set('concurrent_test', 'value1');
		$options->set('concurrent_test', 'value2');
		$options->set('concurrent_test', 'value3');

		$final = $options->get('concurrent_test');
		static::assertEquals('value3', $final, 'Должно вернуться последнее установленное значение');

		// Проверяем, что в БД только одна запись
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'concurrent_test']
		)->queryScalar();

		static::assertEquals(1, $count);
	}
}

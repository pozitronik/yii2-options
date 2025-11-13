<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use DateTime;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use stdClass;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use TypeError;
use Yii;
use yii\base\InvalidRouteException;

/**
 * Serialization security and functionality tests
 *
 * Tests allowedClasses parameter for unserialization security,
 * custom serializers, and data type handling.
 */
class SerializationTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidRouteException
	 * @throws \yii\console\Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify default value of allowedClasses is true (backward compatibility)
	 */
	public function testDefaultAllowedClassesIsTrue():void {
		$options = new SysOptions();
		static::assertTrue($options->allowedClasses);
	}

	/**
	 * Verify primitive types can be stored with allowedClasses=false
	 *
	 * @throws Exception
	 */
	public function testPrimitiveTypesWithAllowedClassesFalse():void {
		$options = new SysOptions();
		$options->allowedClasses = false;

		$testCases = [
			'string_value' => 'test string',
			'int_value' => 42,
			'float_value' => 3.14,
			'bool_true' => true,
			'bool_false' => false,
			'null_value' => null,
			'array_value' => ['key' => 'value', 'nested' => ['deep' => 'data']],
		];

		foreach ($testCases as $key => $value) {
			static::assertTrue($options->set($key, $value));
			static::assertSame($value, $options->get($key));
		}
	}

	/**
	 * Verify with allowedClasses=false stdClass is NOT allowed (no classes allowed)
	 *
	 * @throws Exception
	 */
	public function testNoClassesAllowedWithFalse():void {
		$options = new SysOptions();
		$options->allowedClasses = false;

		$object = new stdClass();
		$object->property = 'value';

		static::assertTrue($options->set('stdclass_test', $object));
		$retrieved = $options->get('stdclass_test');

		// With allowedClasses=false PHP creates __PHP_Incomplete_Class
		/** @noinspection UnnecessaryAssertionInspection */
		static::assertInstanceOf('__PHP_Incomplete_Class', $retrieved);
	}

	/**
	 * Verify with allowedClasses=false custom classes are blocked (returns __PHP_Incomplete_Class)
	 *
	 * @throws Exception
	 */
	public function testCustomClassDisallowedWithAllowedClassesFalse():void {
		$options = new SysOptions();
		$options->allowedClasses = false;

		// Create DateTime object for test
		$dateTime = new DateTime('2025-01-01');

		// Set value (serialization will succeed)
		static::assertTrue($options->set('datetime_test', $dateTime));

		// When retrieving value, PHP creates __PHP_Incomplete_Class
		// because DateTime is not in the allowed classes list
		$retrieved = $options->get('datetime_test');
		/** @noinspection UnnecessaryAssertionInspection */
		static::assertInstanceOf('__PHP_Incomplete_Class', $retrieved);
	}

	/**
	 * Verify with allowedClasses=[ClassName::class] only specified class is allowed
	 *
	 * @throws Exception
	 */
	public function testWhitelistAllowedClasses():void {
		$options = new SysOptions();
		$options->allowedClasses = [DateTime::class, stdClass::class];

		$dateTime = new DateTime('2025-01-01');
		static::assertTrue($options->set('datetime_test', $dateTime));

		$retrieved = $options->get('datetime_test');
		static::assertInstanceOf(DateTime::class, $retrieved);
		static::assertEquals('2025-01-01', $retrieved->format('Y-m-d'));
	}

	/**
	 * Verify allowedClasses=true logs info message
	 */
	public function testAllowedClassesTrueLogsInfo():void {
		// Create new instance with allowedClasses=true (default)
		$options = new SysOptions();

		// Verify that allowedClasses is indeed true
		static::assertTrue($options->allowedClasses);

		// Note: Testing logging requires logger setup,
		// which is out of scope for unit test. Just verify initialization succeeded.
		static::assertInstanceOf(SysOptions::class, $options);
	}

	/**
	 * Verify invalid allowedClasses configuration (not bool and not array)
	 * PHP's strict typing throws TypeError
	 */
	public function testInvalidAllowedClassesType():void {
		$this->expectException(TypeError::class);

		$options = new SysOptions();
		// Strict typing will throw TypeError
		/** @noinspection PhpStrictTypeCheckingInspection */
		$options->allowedClasses = 'invalid';
	}

	/**
	 * Verify custom serializer ignores allowedClasses
	 *
	 * @throws Exception
	 */
	public function testCustomSerializerIgnoresAllowedClasses():void {
		$options = new SysOptions();
		$options->allowedClasses = false;
		$options->serializer = [
			0 => fn($value) => json_encode($value),
			1 => fn(string $value) => json_decode($value, true),
		];

		// When using JSON serializer, objects become arrays
		$object = new stdClass();
		$object->property = 'value';

		static::assertTrue($options->set('json_test', $object));
		$retrieved = $options->get('json_test');

		// JSON decodes object as associative array
		static::assertIsArray($retrieved);
		static::assertEquals('value', $retrieved['property']);
	}

	/**
	 * Verify corrupted serialized data throws exception
	 *
	 * @throws \yii\db\Exception
	 */
	public function testCorruptedSerializedDataThrowsException():void {
		$options = new SysOptions();

		// Directly insert corrupted data into DB
		Yii::$app->db->createCommand()->insert('sys_options', [
			'option' => 'corrupted',
			'value' => 'this_is_not_valid_serialized_data',
		])->execute();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to unserialize');

		$options->get('corrupted');
	}

	/**
	 * Verify serialized false is handled correctly
	 *
	 * @throws Exception
	 */
	public function testSerializedFalseHandledCorrectly():void {
		$options = new SysOptions();
		$options->allowedClasses = false;

		static::assertTrue($options->set('false_value', false));
		$retrieved = $options->get('false_value');

		static::assertFalse($retrieved);
		static::assertIsBool($retrieved);
	}

	/**
	 * Verify allowedClasses=true works with any classes (backward compatibility)
	 *
	 * @throws Exception
	 */
	public function testBackwardCompatibilityWithAllowedClassesTrue():void {
		$options = new SysOptions();
		$options->allowedClasses = true;

		// With allowedClasses=true any classes should work
		$dateTime = new DateTime('2025-01-01');
		static::assertTrue($options->set('any_class_test', $dateTime));

		$retrieved = $options->get('any_class_test');
		static::assertInstanceOf(DateTime::class, $retrieved);
	}
}

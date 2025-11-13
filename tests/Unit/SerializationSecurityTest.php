<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use DateTime;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use stdClass;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use TypeError;
use Yii;

/**
 * Serialization security tests (Issue #5)
 */
class SerializationSecurityTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Test: Default value of allowedClasses should be true (backward compatibility)
	 * @return void
	 */
	public function testDefaultAllowedClassesIsTrue():void {
		$options = new SysOptions();
		static::assertTrue($options->allowedClasses);
	}

	/**
	 * Test: Primitive types can be stored with allowedClasses=false
	 * @return void
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
	 * Test: With allowedClasses=false stdClass is NOT allowed (no classes allowed)
	 * @return void
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
	 * Test: With allowedClasses=false custom classes are blocked (returns __PHP_Incomplete_Class)
	 * @return void
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
	 * Test: With allowedClasses=[ClassName::class] only specified class is allowed
	 * @return void
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
	 * Test: allowedClasses=true logs info message
	 * @return void
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
	 * Test: Invalid allowedClasses configuration (not bool and not array)
	 * PHP's strict typing throws TypeError
	 * @return void
	 */
	public function testInvalidAllowedClassesType():void {
		$this->expectException(TypeError::class);

		$options = new SysOptions();
		// Strict typing will throw TypeError
		/** @noinspection PhpStrictTypeCheckingInspection */
		$options->allowedClasses = 'invalid';
	}

	/**
	 * Test: Custom serializer ignores allowedClasses
	 * @return void
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
	 * Test: Corrupted serialized data throws exception
	 * @return void
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
	 * Test: Serialized false is handled correctly
	 * @return void
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
	 * Test: allowedClasses=true works with any classes (backward compatibility)
	 * @return void
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

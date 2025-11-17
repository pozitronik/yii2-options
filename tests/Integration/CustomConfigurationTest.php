<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Throwable;
use Yii;
use yii\base\Exception as BaseException;
use yii\base\InvalidRouteException;

/**
 * Custom configuration tests
 *
 * Tests custom serializers, custom table names, and configuration validation.
 */
class CustomConfigurationTest extends Unit {

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
	 * Verify empty serializer configuration is rejected
	 *
	 * With PHP 8.4 strict typing, we can't assign non-array to $serializer property,
	 * so this test validates that empty array is rejected.
	 */
	public function testInvalidSerializerConfiguration():void {
		$this->expectException(Throwable::class);

		// Empty array - invalid configuration
		new SysOptions(['serializer' => []]);
	}

	/**
	 * Verify serializer validation - array without required indices
	 */
	public function testSerializerMissingIndices():void {
		$this->expectException(Throwable::class);

		// Array without index 0
		new SysOptions(['serializer' => [1 => fn(string $v) => json_decode($v, true)]]);
	}

	/**
	 * Verify serializer validation - non-callable values
	 */
	public function testSerializerNotCallable():void {
		$this->expectException(Throwable::class);

		// Non-callable values
		new SysOptions(['serializer' => [0 => 'not_callable', 1 => 'also_not_callable']]);
	}

	/**
	 * Verify custom serializer functionality (JSON)
	 *
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
	 * Verify custom table name configuration
	 *
	 * @throws BaseException
	 * @throws Throwable
	 */
	public function testCustomTableNameConfiguration():void {
		// Create custom table
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

		// Create new instance with custom table name
		$options = new SysOptions();
		$options->tableName = $customTableName;

		static::assertTrue($options->set('custom_table_test', 'test_value'));
		static::assertEquals('test_value', $options->get('custom_table_test'));

		// Check that data is in custom table
		$value = Yii::$app->db->createCommand(
			"SELECT value FROM {$customTableName} WHERE option = :option",
			[':option' => 'custom_table_test']
		)->queryScalar();

		static::assertNotFalse($value, 'Data should be in custom table');

		// Cleanup
		Yii::$app->db->createCommand()->dropTable($customTableName)->execute();
	}
}

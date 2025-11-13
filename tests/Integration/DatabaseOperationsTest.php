<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Yii;
use yii\base\Exception as BaseException;
use yii\base\InvalidRouteException;
use yii\console\Exception;

/**
 * Database operations tests
 *
 * Tests upsert behavior, concurrent operations, and table name accessibility.
 */
class DatabaseOperationsTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidRouteException
	 * @throws Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify upsert behavior (updating existing value uses UPDATE, not INSERT)
	 *
	 * @throws BaseException
	 */
	public function testUpsertBehavior():void {
		$options = new SysOptions();

		// Create option
		static::assertTrue($options->set('upsert_test', 'initial_value'));
		static::assertEquals('initial_value', $options->get('upsert_test'));

		// Update same option
		static::assertTrue($options->set('upsert_test', 'updated_value'));
		static::assertEquals('updated_value', $options->get('upsert_test'));

		// Check that DB has only one record (UPDATE, not INSERT)
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'upsert_test']
		)->queryScalar();

		static::assertEquals(1, $count, 'Should be only one record (UPDATE instead of INSERT)');
	}

	/**
	 * Verify concurrent operations (multiple operations on same option)
	 *
	 * @throws BaseException
	 */
	public function testConcurrentOperations():void {
		$options = new SysOptions();

		// Simulate multiple operations with one option
		$options->set('concurrent_test', 'value1');
		$options->set('concurrent_test', 'value2');
		$options->set('concurrent_test', 'value3');

		$final = $options->get('concurrent_test');
		static::assertEquals('value3', $final, 'Should return last set value');

		// Check that DB has only one record
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'concurrent_test']
		)->queryScalar();

		static::assertEquals(1, $count);
	}

	/**
	 * Verify table name is accessible via public property
	 */
	public function testTableNameAccessibility():void {
		$options = new SysOptions();

		// Table name should be publicly accessible
		$tableName = $options->tableName;
		static::assertEquals('sys_options', $tableName, 'Should have access to table name');
	}
}

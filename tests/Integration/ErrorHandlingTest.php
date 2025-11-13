<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Yii;
use yii\base\InvalidRouteException;
use yii\console\Exception;
use yii\db\Exception as DbException;

/**
 * Database error handling tests
 *
 * Tests graceful error handling when database operations fail,
 * ensuring methods return false or default values instead of throwing exceptions.
 */
class ErrorHandlingTest extends Unit {

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
	 * Verify error handling on database read
	 *
	 * When database read fails, should return default value instead of throwing exception.
	 *
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseRead():void {
		$options = new SysOptions();

		// Temporarily drop table to simulate error
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should handle error, not throw exception
		$result = $options->get('any_option', 'default_value');

		// After fix: should return default or null, not throw exception
		static::assertEquals('default_value', $result,
			'On DB error should return default value');
	}

	/**
	 * Verify error handling on database write
	 *
	 * When database write fails, should return false instead of throwing exception.
	 *
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseWrite():void {
		$options = new SysOptions();

		// Save value for verification
		$options->set('test_before_drop', 'value');

		// Drop table
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should return false, not throw exception
		$result = $options->set('test_option', 'test_value');
		static::assertFalse($result, 'On DB error set() should return false');
	}

	/**
	 * Verify error handling on database delete
	 *
	 * When database delete fails, should return false instead of throwing exception.
	 *
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseDelete():void {
		$options = new SysOptions();

		// Drop table
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should return false, not throw exception
		$result = $options->drop('test_option');
		static::assertFalse($result, 'On DB error drop() should return false');
	}
}

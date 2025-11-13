<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Throwable;
use yii\base\InvalidRouteException;

/**
 * Null value handling tests
 *
 * Tests distinction between explicitly stored null values and non-existent options,
 * ensuring proper default value handling.
 */
class NullValueHandlingTest extends Unit {

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
	 * Verify explicit null value storage
	 *
	 * When option is explicitly set to null, get() should return null, not default value.
	 * This distinguishes "option doesn't exist" from "option set to null".
	 *
	 * @throws Exception
	 */
	public function testExplicitNullValueStorage():void {
		$options = new SysOptions();

		// Set explicit null
		static::assertTrue($options->set('null_option', null));

		// Get value - should return null, not default
		$value = $options->get('null_option', 'default_value');

		// Expect null to be returned, not 'default_value'
		static::assertNull($value, 'Explicitly set null should be returned as null');

		// Check non-existent option - should return default
		$nonExistent = $options->get('non_existent_option', 'default_value');
		static::assertEquals('default_value', $nonExistent, 'Non-existent option should return default');
	}

	/**
	 * Verify distinction between null and non-existent option
	 *
	 * @throws Throwable
	 */
	public function testNullVsNonExistentOption():void {
		$options = new SysOptions();

		// Set null
		$options->set('explicit_null', null);

		// Check that we can distinguish null from non-existent option
		$nullValue = $options->get('explicit_null', 'default_for_null');
		$nonExistent = $options->get('never_set', 'default_for_nonexistent');

		// After fix: null should return null, non-existent should return default
		static::assertNotEquals($nullValue, $nonExistent, 'Explicitly set null should differ from non-existent option');
	}
}

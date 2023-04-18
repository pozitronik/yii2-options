<?php
declare(strict_types = 1);

namespace Tests\Support\Helper;

use Yii;
use yii\base\InvalidRouteException;
use yii\console\controllers\MigrateController;
use yii\console\Exception;

/**
 *
 */
class MigrationHelper {
	/**
	 * @return void
	 * @throws InvalidRouteException
	 * @throws Exception
	 */
	public static function migrate(array $migrationConfiguration = []):void {
		$migrationController = new MigrateController('migrations', Yii::$app);
		$migrationController->interactive = false;
		foreach ($migrationConfiguration as $param => $value) {
			$migrationController->{$param} = $value;
		}
		$migrationController->runAction('up');
	}

	/**
	 * @return void
	 * @throws Exception
	 * @throws InvalidRouteException
	 */
	public static function migrateFresh(array $migrationConfiguration = []):void {
		$migrationController = new MigrateController('migrations', Yii::$app);
		$migrationController->interactive = false;
		foreach ($migrationConfiguration as $param => $value) {
			$migrationController->{$param} = $value;
		}
		$migrationController->runAction('fresh');
	}
}
<?php
declare(strict_types = 1);

namespace Tests\Unit;

use app\fixtures\BooksFixture;
use app\fixtures\PartnersFixture;
use app\fixtures\UsersFixture;
use Codeception\Test\Unit;
use Exception;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Yii;
use yii\base\Application;
use yii\base\InvalidRouteException;

/**
 *
 */
class MainTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @return string[]
	 * @throws Exception
	 * @throws InvalidRouteException
	 */
	public function _fixtures():array {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
		return ['users' => UsersFixture::class, 'books' => BooksFixture::class, 'partners' => PartnersFixture::class,];
	}

	/**
	 * @return void
	 */
	public function testSomeFeature():void {
		$this->tester->assertInstanceOf(Application::class, Yii::$app);
	}
}

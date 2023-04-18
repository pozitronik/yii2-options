<?php
declare(strict_types = 1);

namespace app\fixtures;

use app\models\Users;
use yii\test\ActiveFixture;

/**
 * UsersFixture
 */
class UsersFixture extends ActiveFixture {
	public $modelClass = Users::class;
}
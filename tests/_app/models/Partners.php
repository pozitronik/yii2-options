<?php
declare(strict_types = 1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 */
class Partners extends ActiveRecord {

	/**
	 * @inheritDoc
	 */
	public static function tableName():string {
		return 'partners';
	}

	/**
	 * {@inheritdoc}
	 */
	public function rules():array {
		return [
			[['name'], 'string'],
			[['name'], 'required']
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function attributeLabels():array {
		return [
			'name' => 'Имя партнёра',
		];
	}
}
<?php
declare(strict_types = 1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $author
 */
class Books extends ActiveRecord {

	/**
	 * @inheritDoc
	 */
	public static function tableName():string {
		return 'books';
	}

	/**
	 * {@inheritdoc}
	 */
	public function rules():array {
		return [
			[['name', 'author'], 'string'],
			[['name', 'author'], 'required']
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function attributeLabels():array {
		return [
			'name' => 'Название',
			'author' => 'Пользователи'
		];
	}
}
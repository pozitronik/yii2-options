yii2-options
=================
Хранение системных настроек на сервере.

Установка
---------

Предпочтительный вариант установки расширения через [composer](http://getcomposer.org/download/).


Выполните

```
php composer.phar require pozitronik/yii2-options "dev-master"
```

или добавьте

```
"pozitronik/yii2-options": "dev-master"
```

В секцию require файла `composer.json` в вашем проекте.

Описание
--------

Модель SysOptions умеет хранить набор произвольных key-value параметров, привязанных к любому объекту (подразумевается, что таким объектом выступает пользователь системы, но, при желании, модель может быть использована и для других объектов).
Данные хранятся в таблице со структурой `id|option_name|option_value,` и модель всего лишь предоставляет интерфейсы для удобного доступа к хранилищу.
Типы данных хранимых значений ограничиваются только используемым методом сериализации. По умолчанию обеспечивается типобезопасное хранение скалярных данных, массивов и объектов без реккурентных ссылок. 

Использование
-------------

Расширению необходима таблица для хранения данных. Её можно создать, выполнив команду:

`yii migrate --migrationPath=@vendor/pozitronik/yii2-options/migrations`

В этом случае будет создана таблица `sys_options`, и никакой дополнительной настройки более не потребуется.

При необходимости можно переопределить имя используемой таблицы. Для этого нужно подключить в конфигурационном файле вашего приложения модуль UsersOptionsModule с именем `usersoptions`, и в его конфигурации указать имя используемой таблицы в параметре `tableName`.

Модель может использовать промежуточное кеширование (при наличии кеша в Yii), это регулируется параметром `cacheEnabled`
Пример конфигурации:
```php
'modules' => [
		'sysoptions' => [
			'class' => SysOptionsModule::class,
			'params' => [
				'tableName' => 'system_options',//используемое имя таблицы, по умолчанию 'dyd_options'
				'cacheEnabled' => true//использование кеша Yii, по умолчанию false
		],
		...
]
```

Публичные параметры класса:
* `Connection|array|string $db = 'db'` -- идентификатор имеющегося соединения с базой данных или конфигурация нового соединения.
* `null|array $serializer = null` -- методы, используемые для сериализации хранимых данных. Если параметр не установлен, то используются стандартные функции `serialize()`/`unserialize()`. Для их переопределения следует задать параметр с помощью замыканий, например:
```php

$options->serializer = [
	0 => function($value) {//функция для сериализации
		return json_encode($value);
	},
	1 => function(string $value) {//функция для десериализации
		return json_decode($value);
	},
];

```

* `bool $cacheEnabled = false` -- включает использование промежуточного кеша. Если параметр не установлен напрямую, используется значение параметра `cacheEnabled` конфигурации модуля. 
* `string $tableName` -- название таблицы, используемое модулем (read-only). 

Публичные методы класса:

* `get(string $option)` - возвращает значение параметра `$option`.
* `set(string $option, $value):bool` - присваивает параметру `$option` значение `$value`. Возвращает успех сохранения параметра.

а также статические методы
* `getStatic(int $user_id, string $option)`
* `setStatic(int $user_id, string $option, $value):bool`

Аналогичные вызовам `get`/`set`.

Лицензия
--------
GNU GPL v3.0
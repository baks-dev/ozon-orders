# BaksDev Ozon Orders

[![Version](https://img.shields.io/badge/version-7.1.12-blue)](https://github.com/baks-dev/ozon-orders/releases)
![php 8.3+](https://img.shields.io/badge/php-min%208.3-red.svg)

Модуль заказов Ozon

## Установка

``` bash
$ composer require baks-dev/ozon-orders
```

Для работы с заказами выполнить комманду для добавления типа профиля и доставку:

* #### FBS

``` bash
php bin/console baks:users-profile-type:ozon-fbs
php bin/console baks:payment:ozon-fbs
php bin/console baks:delivery:ozon-fbs
```

* #### DBS

``` bash
php bin/console baks:users-profile-type:ozon-dbs
php bin/console baks:payment:ozon-dbs
php bin/console baks:delivery:ozon-dbs
```

Тесты

``` bash
$ php bin/phpunit --group=ozon-orders
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.

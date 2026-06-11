<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Ozon\Orders\Api\Accrual\Types\Tests;

use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\Accrual\Types\GetOzonOrderAccrualTypesRequest;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[Group('ozon-orders')]
#[When(env: 'test')]
class GetOzonOrderAccrualTypesRequestTest extends KernelTestCase
{
    private static OzonAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        OrderNewTest::setUpBeforeClass();
        PackageProductStockTest::setUpBeforeClass();
        UserNewUserProfileHandleTest::setUpBeforeClass();

        self::$Authorization = new OzonAuthorizationToken(
            new UserProfileUid('018d464d-c67a-7285-8192-7235b0510924'),
            $_SERVER['TEST_OZON_TOKEN'],
            TypeProfileFbsOzon::TYPE,
            $_SERVER['TEST_OZON_CLIENT'],
            $_SERVER['TEST_OZON_WAREHOUSE'],
            '10',
            0,
            false,
            false,
        );
    }

    public function testUseCase(): void
    {
        /** @var GetOzonOrderAccrualTypesRequest $GetOzonOrderAccrualTypesRequest */
        $GetOzonOrderAccrualTypesRequest = self::getContainer()->get(GetOzonOrderAccrualTypesRequest::class);
        $GetOzonOrderAccrualTypesRequest->TokenHttpClient(self::$Authorization);

        $types = $GetOzonOrderAccrualTypesRequest->findAll();

        self::assertNotFalse($types);

        // Собираем из актуальных данных ассоциативный массив [id => description]
        $actualMap = [];
        foreach($types as $item)
        {
            $actualMap[$item['id']] = $item['description'];
        }

        // Проверяем, что для каждого id из actual match возвращает то же описание
        foreach($actualMap as $id => $expectedDescription)
        {
            $actualDescription = $this->getDescriptionFromMatch($id);
            $this->assertNotNull($actualDescription, "ID {$id} отсутствует в match, но присутствует в актуальных данных");
            $this->assertEquals($expectedDescription, $actualDescription, "Несоответствие описания для ID {$id}");
        }

        // Проверяем, что в match нет лишних id, которых нет в actual
        $maxPossibleId = 200; // указываем заведомо большое число
        for($id = 1; $id <= $maxPossibleId; $id++)
        {
            $description = $this->getDescriptionFromMatch($id);

            if($description !== null)
            {
                $this->assertArrayHasKey($id, $actualMap, "ID {$id} присутствует в match, но отсутствует в актуальных данных");
            }
        }
    }

    /**
     * Тестируемая функция, использующая match.
     */
    private function getDescriptionFromMatch(int $id): ?string
    {
        return match ($id)
        {
            1 => "Эквайринг",
            2 => "Обратная магистраль",
            3 => "Продвижение бренда",
            4 => "Подключение продвижения бренда",
            5 => "Брендовая полка",
            6 => "Обработка отменённых и невостребованных товаров",
            7 => "Благотворительное пожертвование",
            8 => "Начисления по претензиям",
            9 => "Обработка возвратов",
            10 => "Компенсация",
            11 => "Инвентаризация взаиморасчетов",
            12 => "Кросс-докинг",
            13 => "Организация выезда курьера",
            14 => "Обработка операционных ошибок продавца",
            15 => "Утилизация",
            16 => "Обработка отправления Drop-off",
            17 => "Обработка отправления Drop-off партнёрами",
            18 => "Досрочная выплата",
            19 => "Внешнее продвижение",
            20 => "Гибкий график выплат",
            21 => "Сборка заказа",
            22 => "Рассрочка",
            23 => "Реклама в сети Интернет на Сайте",
            24 => "Перенос карточек товаров",
            25 => "Товарная компенсация",
            26 => "Рассрочка для покупателей из Казахстана",
            27 => "Бейдж Оригинал",
            28 => "Последняя миля",
            29 => "Доставка до места выдачи",
            30 => "Выдача товара",
            31 => "Лидогенерация для автодилеров",
            32 => "Логистика",
            33 => "Рекламные услуги",
            34 => "Обязательная маркировка товаров",
            35 => "Модерация товаров",
            36 => "Привлечение предварительных заказов",
            37 => "Ozon Data",
            38 => "Обеспечение материалами для упаковки товара",
            39 => "Упаковка товара партнёрами",
            40 => "Обработка частичного невыкупа",
            41 => "Оплата за клик",
            42 => "Обработка отправления Pick-up",
            43 => "Организация выезда курьера",
            44 => "Доставка курьером Pick-up",
            45 => "Обработка возвратов, отмен и невыкупов партнёрами",
            46 => "Размещение товаров на складах Ozon",
            47 => "Баллы за отзывы",
            48 => "Бонусы продавца",
            49 => "Услуга продвижения Premium",
            50 => "Бонусы продавца - рассылка",
            51 => "Подписка Premium Pro (процент)",
            52 => "Подписка Premium",
            53 => "Подготовка товаров к возврату",
            54 => "Продвижение товара",
            55 => "Рассылка пуш-уведомлений",
            56 => "Обработка и логистика кванта",
            57 => "Корректировка стоимости услуг",
            58 => "Перемещение товаров между складами Ozon",
            59 => "Обратная логистика",
            60 => "Долгосрочное размещение возврата FBS",
            61 => "Закрепление отзыва",
            62 => "Перечисление за доставку от покупателя",
            63 => "Агентское вознаграждение Ozon Агрегатор realFBS",
            64 => "Доставка Партнёром Ozon",
            65 => "Лёгкий возврат",
            66 => "Агентское вознаграждение Ozon",
            67 => "Услуги международной доставки",
            68 => "Сервисный сбор за интеграцию с логистической платформой",
            69 => "Вознаграждение за продажу",
            70 => "Приобретение отзывов на платформе",
            71 => "Вывоз товара со склада силами Ozon",
            72 => "Взаимозачет требований между Договорами",
            73 => "Магистраль",
            74 => "Звёздные товары",
            75 => "Трафареты",
            76 => "Страхование товара от массовых повреждений",
            77 => "Обработка товара",
            78 => "Краткосрочное размещение возврата FBS",
            79 => "Временное размещение товара партнерами",
            80 => "Генерация видеообложки",
            81 => "Бонус за достижение цели продаж",
            82 => "Дополнительная обработка ОВХ",
            83 => "Обеспечительные платежи",
            84 => "Дополнительная упаковка на складе Ozon",
            85 => "Пломбирование товара",
            86 => "Дополнительная упаковка товара на ПВЗ в СНГ",
            87 => "Реклама в социальных сетях",
            88 => "Самовывоз",
            89 => "Запрещённый контент",
            90 => "Запрещённый товар",
            91 => "Товар с нарушением интеллектуальных прав",
            92 => "Жалобы покупателей",
            93 => "Превышение индекса ошибок",
            94 => "Отгрузка в нерекомендованный слот",
            95 => "Подписка Управление отзывами",
            96 => "Ускоренный сбор отзывов",
            97 => "Обработка грузоместа",
            98 => "Доставка до места выдачи силами Ozon",
            99 => "Международная логистика",
            100 => "Транспортно-экспедиционная услуга по организации международной перевозки",
            101 => "Обработка нестандартного товара",
            default => null,
        };
    }
}

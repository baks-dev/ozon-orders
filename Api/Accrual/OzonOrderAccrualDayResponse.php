<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\Api\Accrual;

use BaksDev\Reference\Money\Type\Money;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OzonOrderAccrualDayResponse */
final class OzonOrderAccrualDayResponse
{
    /**
     * Идентификатор начисления.
     */
    private string $id;

    /**
     * Тип начисления:
     *
     * UNSPECIFIED — не определён;
     * POSTING — начисления по отправлению;
     * ITEM — начисления по товару;
     * NON_ITEM — начисление по продавцу без привязки к товару.
     */
    private string $type;

    /**
     * Дата начисления
     */
    private DateTimeImmutable $date;

    /**
     * Общая сумма начислений.
     */
    private Money $total;

    /**
     * Идентификатор заказа или услуги. Например, номер отправления или номер рекламного договора.
     */
    private string $number;

    /** Идентификатор товара при "Начисления по товарам" */
    private ?string $article;

    /** Комментарий */
    private ?string $comment = null;

    public function __construct(array $item, string|null|false $article = null)
    {
        $this->id = (string) $item['accrual_id'];
        $this->date = new DateTimeImmutable($item['date']);
        $this->total = new Money($item['total_amount']['amount']);
        $this->type = (string) $item['accrued_category'];
        $this->article = $article ?: null;
        $this->number = isset($item['unit_number']) ? (string) $item['unit_number'] : $item['date']; // Если нет номера - присваиваем дату

        $comments = null;

        if(isset($item['non_item_fee']['type_id']))
        {
            $comments[] = $this->getCommentType($item['non_item_fee']['type_id']);
        }

        if(isset($item['item_fees']))
        {
            foreach($item['item_fees'] as $fees)
            {
                foreach($fees as $fee)
                {
                    foreach($fee['fees'] as $fee_fees)
                    {
                        $comments[] = $this->getCommentType($fee_fees['type_id']);
                    }
                }
            }
        }

        $this->comment = empty($comments) ? null : implode(', ', $comments);

    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function getTotal(): Money
    {
        return $this->total;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getArticle(): ?string
    {
        return $this->article;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    private function getCommentType(int $id)
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
            default => "Не определённый тип начисления",
        };
    }
}
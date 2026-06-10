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

    public function __construct(array $item, string|null|false $article = null)
    {
        $this->id = (string) $item['accrual_id'];
        $this->date = new DateTimeImmutable($item['date']);
        $this->total = new Money($item['total_amount']['amount']);
        $this->number = (string) $item['unit_number'];
        $this->type = (string) $item['accrued_category'];
        $this->article = $article ?: null;
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
        return 'O-'.$this->number;
    }

    public function getArticle(): ?string
    {
        return $this->article;
    }
}
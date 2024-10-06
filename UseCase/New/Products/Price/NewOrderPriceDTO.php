<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\UseCase\New\Products\Price;

use BaksDev\Orders\Order\Entity\Products\Price\OrderPriceInterface;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use Symfony\Component\Validator\Constraints as Assert;

final class NewOrderPriceDTO implements OrderPriceInterface
{
    /** Количество в заказе */
    #[Assert\NotBlank]
    private int $total = 1;

    /** Стоимость */
    private ?Money $price;

    /** Валюта */
    #[Assert\NotBlank]
    private Currency $currency;


    public function __construct()
    {
        $this->currency = new Currency();
    }

    /** Количество в заказе */
    public function getTotal(): int
    {
        return $this->total;
    }


    public function setTotal(int $total): void
    {
        $this->total = $total;
    }


    /** Стоимость */

    public function getPrice(): Money
    {
        return $this->price;
    }


    public function setPrice(Money $price): void
    {
        $this->price = $price;
    }


    /** Валюта */

    public function getCurrency(): Currency
    {
        return $this->currency;
    }


    public function setCurrency(Currency $currency): void
    {
        $this->currency = $currency;
    }

}

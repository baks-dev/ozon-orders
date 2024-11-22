<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\UseCase\New;

use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\DeliveryTransport\Type\OrderStatus\OrderStatusDelivery;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFbsOzon;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class NewOzonOrderDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Идентификатор заказа YandexMarket */
    private string $number;

    /** Постоянная величина */
    #[Assert\Valid]
    private Invariable\NewOrderInvariable $invariable;

    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;

    /** Статус заказа */
    private OrderStatus $status;

    /** Коллекция продукции в заказе */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private User\OrderUserDTO $usr;

    /** Ответственный */
    private ?UserProfileUid $profile = null;

    /** Комментарий к заказу */
    private ?string $comment = null;

    public function __construct(array $order, UserProfileUid $profile)
    {
        $timezone = new DateTimeZone(date_default_timezone_get());

        /** Постоянная величина */
        $NewOrderInvariable = new Invariable\NewOrderInvariable();

        $created = (new DateTimeImmutable($order['in_process_at']))->setTimezone($timezone);
        $NewOrderInvariable->setCreated($created); // Дата и время начала обработки отправления.

        $NewOrderInvariable->setProfile($profile);
        $NewOrderInvariable->setNumber('O-'.$order['posting_number']); // помечаем заказ префиксом O
        //$NewOrderInvariable->setNumber('O-'.$order['order_number']); // помечаем заказ префиксом O (без единицы в конце)
        $this->invariable = $NewOrderInvariable;


        /** @deprecated переносится в Invariable */
        $this->number = 'O-'.$order['posting_number']; // помечаем заказ префиксом O
        // $this->number = 'O-'.$order['order_number']; // помечаем заказ префиксом O (без единицы в конце)
        $this->created = $created; // Дата и время начала обработки отправления.


        /** Определяем статус заказа */
        $yandexStatus = match ($order['status'])
        {
            'cancelled' => OrderStatusCanceled::class, // заказ отменен
            'delivering', 'driver_pickup' => OrderStatusDelivery::class, // заказ передан в службу доставки
            default => OrderStatusNew::class,
        };


        $this->status = new OrderStatus($yandexStatus);

        $this->product = new ArrayCollection();
        $this->usr = new User\OrderUserDTO();

        $OrderDeliveryDTO = $this->usr->getDelivery();
        $OrderPaymentDTO = $this->usr->getPayment();
        $OrderProfileDTO = $this->usr->getUserProfile();

        /** Дата доставки */
        $deliveryDate = (new DateTimeImmutable($order['tariffication']['next_tariff_starts_at']))->setTimezone($timezone);
        $OrderDeliveryDTO->setDeliveryDate($deliveryDate);

        // Доставка Озон FBS
        if($order['tpl_integration_type'] === 'ozon')
        {
            /** Тип профиля FBS Озон */
            $Profile = new TypeProfileUid(TypeProfileFbsOzon::class);
            $OrderProfileDTO?->setType($Profile);

            /** Способ доставки Yandex Market (FBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryFbsOzon::class);
            $OrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты FBS Yandex Market */
            $Payment = new PaymentUid(TypePaymentFbsOzon::class);
            $OrderPaymentDTO->setPayment($Payment);

            /** Комментарий покупателя */
            //$this->comment = str_replace(' самостоятельно', '', $order['delivery_method']['name']);
        }


        /** Продукция */
        foreach($order['products'] as $item)
        {
            $NewOrderProductDTO = new Products\NewOrderProductDTO($item['offer_id']);
            $NewOrderProductDTO->setSku($item['sku']);

            $NewOrderPriceDTO = $NewOrderProductDTO->getPrice();

            $Money = new Money($item['price']); // Стоимость товара в валюте магазина до применения скидок.
            $Currency = new Currency($item['currency_code']);

            $NewOrderPriceDTO->setPrice($Money);
            $NewOrderPriceDTO->setCurrency($Currency);
            $NewOrderPriceDTO->setTotal($item['quantity']);


            $this->addProduct($NewOrderProductDTO);

        }
    }

    /** @see OrderEvent */
    public function getEvent(): ?OrderEventUid
    {
        return $this->id;
    }

    public function setId(?OrderEventUid $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getStatusEquals(mixed $status): bool
    {
        return $this->status->equals($status);
    }

    public function setStatus(OrderStatus|OrderStatusInterface|string $status): self
    {
        $this->status = new OrderStatus($status);
        return $this;
    }


    /**
     * Number
     */
    public function getNumber(): string
    {
        return $this->number;
    }


    /** Коллекция продукции в заказе */

    public function getProduct(): ArrayCollection
    {
        return $this->product;
    }

    public function setProduct(ArrayCollection $product): void
    {
        $this->product = $product;
    }

    public function addProduct(Products\NewOrderProductDTO $product): void
    {
        $filter = $this->product->filter(function(Products\NewOrderProductDTO $element) use ($product) {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }
    }

    public function removeProduct(Products\NewOrderProductDTO $product): void
    {
        $this->product->removeElement($product);
    }

    /**
     * Usr
     */
    public function getUsr(): User\OrderUserDTO
    {
        return $this->usr;
    }

    /**
     * Comment
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Invariable
     */
    public function getInvariable(): Invariable\NewOrderInvariable
    {
        return $this->invariable;
    }

    /**
     * Profile
     */
    public function getProfile(): ?UserProfileUid
    {
        return $this->profile;
    }

    public function resetProfile(?UserProfileUid $profile = null): self
    {
        $this->profile = $profile;
        return $this;
    }
}

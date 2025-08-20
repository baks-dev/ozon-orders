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

namespace BaksDev\Ozon\Orders\UseCase\New;

use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\DeliveryTransport\Type\OrderStatus\OrderStatusDelivery;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusInterface;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryDbsOzon;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentDbsOzon;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFbsOzon;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileDbsOzon;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class NewOzonOrderDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /**
     * Идентификатор заказа Ozon (для дедубликтора)
     */
    private string $number;

    /** Постоянная величина */
    #[Assert\Valid]
    private Invariable\NewOrderInvariable $invariable;

    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;

    /** Статус заказа */
    private OrderStatus $status;

    /**
     * Коллекция продукции в заказе
     *
     * @var ArrayCollection{int, NewOrderProductDTO} $product
     */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private User\OrderUserDTO $usr;

    /** Ответственный */
    private ?UserProfileUid $profile = null;

    /** Комментарий к заказу */
    private ?string $comment = null;

    /** Информация о покупателе */
    private ?array $buyer;

    /**
     * Связанные отправления — те, на которое было разделено родительское отправление при сборке.
     */
    private ?array $relatedPostings = null;

    public function __construct(array $order, UserProfileUid $profile, OzonTokenUid|false $identifier = false)
    {
        // Дата и время начала обработки отправления.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $this->created = (new DateTimeImmutable($order['in_process_at']))->setTimezone($timezone);

        /** Постоянная величина */
        $this->invariable = new Invariable\NewOrderInvariable()
            ->setCreated($this->created) // Дата и время начала обработки отправления.
            ->setProfile($profile) // идентификатор профиля бизнес-аккаунта
            ->setToken($identifier) // идентификатор токена маркетплейса
            ->setNumber('O-'.$order['posting_number']) // помечаем заказ префиксом O
        ;

        $this->number = $order['order_number']; // помечаем заказ для дедубликатора

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


        /**
         * Дата доставки
         */


        // По умолчанию дата доставки - на след. день
        $deliveryDate = new DateTimeImmutable('+ 1 day');

        if(isset($order['tariffication']['next_tariff_starts_at']))
        {
            /** Дата доставки */
            $deliveryDate = (new DateTimeImmutable($order['tariffication']['next_tariff_starts_at']))->setTimezone($timezone);
        }

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


        }

        // non_integrated — Озон DBS (доставка сторонней службой)
        if($order['tpl_integration_type'] === 'non_integrated')
        {
            /** Тип профиля DBS Озон */
            $Profile = new TypeProfileUid(TypeProfileDbsOzon::class);
            $OrderProfileDTO?->setType($Profile);

            /** Способ доставки Yandex Market (DBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryDbsOzon::class);
            $OrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты DBS Yandex Market */
            $Payment = new PaymentUid(TypePaymentDbsOzon::class);
            $OrderPaymentDTO->setPayment($Payment);

            /** Дата доставки */
            $deliveryDate = new DateTimeImmutable($order['shipment_date']);
            $OrderDeliveryDTO->setDeliveryDate($deliveryDate);

            $address = $order['customer']['address'];
            $OrderDeliveryDTO->setAddress($address['address_tail']);

            /** Геолокация клиента */
            $OrderDeliveryDTO->setLatitude(new GpsLatitude($address['latitude']));
            $OrderDeliveryDTO->setLongitude(new GpsLongitude($address['longitude']));

            /** Информация о покупателе */
            $this->buyer = empty($order['addressee']) ? null : $order['addressee'];

            /** Комментарий покупателя */
            $this->comment = $order['customer']['address']['comment'];

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

        if(isset($order['related_postings']['related_posting_numbers']))
        {
            /** Дата доставки */
            $this->relatedPostings = $order['related_postings']['related_posting_numbers'];
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
    public function getOrderNumber(): string
    {
        return $this->number;
    }


    /**
     * Коллекция продукции в заказе
     *
     * @return  ArrayCollection{int, NewOrderProductDTO}
     */
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

    /**
     * Buyer
     */
    public function getBuyer(): ?array
    {
        return $this->buyer;
    }

    /**
     * Связанные отправления — те, на которое было разделено родительское отправление при сборке.
     */
    public function getRelatedPostings(): ?array
    {
        return $this->relatedPostings;
    }
}

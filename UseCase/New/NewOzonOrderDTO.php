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

use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\DeliveryTransport\Type\OrderStatus\OrderStatusDelivery;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;

use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFbsOzon;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryYandexMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentYandex;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileYandexMarket;
use DateTimeImmutable;
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
        /** Постоянная величина */
        $NewOrderInvariable = new Invariable\NewOrderInvariable();
        $NewOrderInvariable->setCreated(new DateTimeImmutable($order['in_process_at'])); // Дата и время начала обработки отправления.
        $NewOrderInvariable->setProfile($profile);
        $NewOrderInvariable->setNumber('O-'.$order['posting_number']); // помечаем заказ префиксом O
        //$NewOrderInvariable->setNumber('O-'.$order['order_number']); // помечаем заказ префиксом O (без единицы в конце)
        $this->invariable = $NewOrderInvariable;


        /** @deprecated переносится в Invariable */
        $this->number = 'O-'.$order['posting_number']; // помечаем заказ префиксом O
        // $this->number = 'O-'.$order['order_number']; // помечаем заказ префиксом O (без единицы в конце)
        $this->created = new DateTimeImmutable($order['in_process_at']); // Дата и время начала обработки отправления.


        /** Определяем статус заказа */
        $yandexStatus = match ($order['status'])
        {
            'cancelled' => OrderStatusCanceled::class, // заказ отменен
            'delivering', 'driver_pickup' => OrderStatusDelivery::class, // заказ передан в службу доставки
            default => OrderStatusNew::class,
        };


        $this->status = new OrderStatus($yandexStatus);

        /** Присваиваем, если имееется информация о покупателе */
        if(!empty($order['addressee']))
        {

        }


        $this->product = new ArrayCollection();
        $this->usr = new User\OrderUserDTO();


        /** Дата доставки */
        $deliveryDate = new DateTimeImmutable($order['shipment_date']);

        $OrderDeliveryDTO = $this->usr->getDelivery();
        $OrderPaymentDTO = $this->usr->getPayment();
        $OrderProfileDTO = $this->usr->getUserProfile();


        $OrderDeliveryDTO->setDeliveryDate($deliveryDate);


        //$address = $order['delivery']['address'];

        /** Геолокация клиента */
        //$OrderDeliveryDTO->setLatitude(new GpsLatitude($address['gps']['latitude']));
        //$OrderDeliveryDTO->setLongitude(new GpsLongitude($address['gps']['longitude']));


        /** Адрес доставки клиента */

        //        $deliveryAddress = [];
        //
        //        foreach($address as $key => $data)
        //        {
        //            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdeliveryaddressdto */
        //            if(
        //                empty($data) ||
        //                in_array($key, [
        //                    'gps', // GPS-координаты.
        //                    'postcode', // Почтовый индекс.
        //                    'recipient', // Фамилия, имя и отчество получателя заказа.
        //                    'subway', // Станция метро.
        //                    'floor', // Этаж
        //                    'phone', // Телефон получателя заказа.
        //                ])
        //            ) {
        //                continue;
        //            }
        //
        //            $deliveryAddress[] = match ($key)
        //            {
        //                //'street' => 'улица '.trim(str_replace('улица', '', $data)),
        //                'house' => 'дом '.$data,
        //                //'block' => 'корпус '.$data,
        //                'entrance' => 'подъезд '.$data,
        //                default => $data,
        //            };
        //        }

        //$OrderDeliveryDTO->setAddress(implode(', ', $deliveryAddress));
        //dump($OrderDeliveryDTO->getAddress());

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
            $this->comment = str_replace(' самостоятельно', '', $order['delivery_method']['name']);
        }


        //        // Доставка Магазином (DBS)
        //        if($order['delivery']['deliveryPartnerType'] === 'SHOP')
        //        {
        //            /** Тип профиля DBS Yandex Market */
        //            $Profile = new TypeProfileUid(TypeProfileDbsYaMarket::class);
        //            $OrderProfileDTO?->setType($Profile);
        //
        //            /** Способ доставки Магазином (DBS Yandex Market) */
        //            $Delivery = new DeliveryUid(TypeDeliveryDbsYaMarket::class);
        //            $OrderDeliveryDTO->setDelivery($Delivery);
        //
        //            /** Способ оплаты DBS Yandex Market  */
        //            $Payment = new PaymentUid(TypePaymentDbsYaMarket::class);
        //            $OrderPaymentDTO->setPayment($Payment);
        //        }

        /** Информация о покупателе */
        //$this->buyer = empty($buyer) ? null : $buyer;

        //        $deliveryComment = [];
        //        $deliveryComment[] = $order['delivery_method']['tpl_provider'];


        //        foreach($address as $key => $data)
        //        {
        //            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdeliveryaddressdto */
        //            if(
        //                empty($data) ||
        //                !in_array($key, [
        //                    //'postcode', // Почтовый индекс.
        //                    'recipient', // Фамилия, имя и отчество получателя заказа.
        //                    'subway', // Станция метро.
        //                    'apartment', // Квартира или офис.
        //                    'floor', // Этаж
        //                    'phone', // Телефон получателя заказа.
        //                ])
        //            ) {
        //                continue;
        //            }
        //
        //            $deliveryComment[] = match ($key)
        //            {
        //                //'postcode' => 'инд. '.$data,
        //                'recipient' => 'получатель '.$data,
        //                'phone' => 'тел. '.$data,
        //                'subway' => 'ст.метро '.$data,
        //                'apartment' => 'кв. '.$data,
        //                'floor' => 'этаж '.$data,
        //                default => $data,
        //            };
        //        }

        //  isset($order['notes']) ? $deliveryComment[] = $order['notes'] : false;

        //dd($this->comment);

        /** Продукция */
        foreach($order['products'] as $item)
        {
            $NewOrderProductDTO = new Products\NewOrderProductDTO($item['offer_id']);

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
        $filter = $this->product->filter(function (Products\NewOrderProductDTO $element) use ($product) {
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

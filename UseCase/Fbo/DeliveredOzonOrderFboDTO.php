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

namespace BaksDev\Ozon\Orders\UseCase\Fbo;

use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFboOzon;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFboOzon;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFboOzon;
use BaksDev\Ozon\Orders\UseCase\Fbo\Invariable\DeliveredOzonOrderFboInvariableDTO;
use BaksDev\Ozon\Orders\UseCase\Fbo\Posting\DeliveredOzonOrderFboPostingDTO;
use BaksDev\Ozon\Orders\UseCase\Fbo\Products\DeliveredOzonOrderFboProductDTO;
use BaksDev\Ozon\Orders\UseCase\Fbo\User\DeliveredOzonOrderFboUserDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

final class DeliveredOzonOrderFboDTO
{
    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;

    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Постоянная величина */
    #[Assert\Valid]
    private DeliveredOzonOrderFboInvariableDTO $invariable;

    #[Assert\Valid]
    private DeliveredOzonOrderFboPostingDTO $posting;

    /** Статус заказа */
    private OrderStatus $status;


    /**
     * Коллекция продукции в заказе
     *
     * @var ArrayCollection<int, DeliveredOzonOrderFboProductDTO> $product
     */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private DeliveredOzonOrderFboUserDTO $usr;

    public function __construct(array $order, OzonTokenUid|false $identifier = false)
    {
        // Дата и время начала обработки отправления.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $this->created = (new DateTimeImmutable($order['created_at']))->setTimezone($timezone);


        /**
         * Постоянная величина
         *
         * @mote профиль пользователя не присваивается, присваивается только USER в обработчике из .env
         */
        $this->invariable = new DeliveredOzonOrderFboInvariableDTO()
            ->setCreated($this->created) // Дата и время начала обработки отправления.
            ->setToken($identifier) // идентификатор токена маркетплейса
            ->setNumber('O-'.$order['order_number']) // помечаем заказ префиксом O
        ;

        /** Номер отправления */
        $this->posting = new DeliveredOzonOrderFboPostingDTO()
            ->setValue('O-'.$order['posting_number']);

        $this->status = new OrderStatus(OrderStatusCompleted::class);

        $this->product = new ArrayCollection();
        $this->usr = new DeliveredOzonOrderFboUserDTO();

        $OrderDeliveryDTO = $this->usr->getDelivery();
        $OrderPaymentDTO = $this->usr->getPayment();
        $OrderProfileDTO = $this->usr->getUserProfile();

        $deliveryDate = new DateTimeImmutable($order['created_at']);

        /** Тип профиля FBS Озон */
        $Profile = new TypeProfileUid(TypeProfileFboOzon::class);
        $OrderProfileDTO?->setType($Profile);

        /** Способ доставки Yandex Market (FBS Yandex Market) */
        $Delivery = new DeliveryUid(TypeDeliveryFboOzon::class);
        $OrderDeliveryDTO->setDelivery($Delivery);

        /** Способ оплаты FBS Yandex Market */
        $Payment = new PaymentUid(TypePaymentFboOzon::class);
        $OrderPaymentDTO->setPayment($Payment);


        /** Продукция */
        foreach($order['products'] as $item)
        {
            /**
             * Информация о продукте
             */

            $NewOrderProductDTO = new DeliveredOzonOrderFboProductDTO($item['offer_id']);
            //$NewOrderProductDTO->setSku($item['sku']);


            /**
             * Стоимость
             */

            $NewOrderPriceDTO = $NewOrderProductDTO->getPrice();

            $Money = new Money($item['price']['amount']); // Стоимость товара в валюте магазина до применения скидок.
            $Currency = new Currency($item['price']['currency']);

            $NewOrderPriceDTO->setPrice($Money);
            $NewOrderPriceDTO->setCurrency($Currency);
            $NewOrderPriceDTO->setTotal($item['quantity']);


            $this->addProduct($NewOrderProductDTO);
        }
    }

    public function addProduct(DeliveredOzonOrderFboProductDTO $product): void
    {
        $filter = $this->product->filter(function(DeliveredOzonOrderFboProductDTO $element) use ($product) {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }
    }

    public function getInvariable(): DeliveredOzonOrderFboInvariableDTO
    {
        return $this->invariable;
    }

    public function getPosting(): DeliveredOzonOrderFboPostingDTO
    {
        return $this->posting;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /** @return ArrayCollection<DeliveredOzonOrderFboProductDTO> */
    public function getProducts(): ArrayCollection
    {
        return $this->product;
    }

    public function getStatusEquals(mixed $status): bool
    {
        return $this->status->equals($status);
    }

    public function getPostingNumber(): string
    {
        return $this->posting->getValue();
    }

    /**
     * Usr
     */
    public function getUsr(): DeliveredOzonOrderFboUserDTO
    {
        return $this->usr;
    }
}
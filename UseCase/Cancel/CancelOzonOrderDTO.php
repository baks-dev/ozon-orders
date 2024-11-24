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

namespace BaksDev\Ozon\Orders\UseCase\Cancel;

use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class CancelOzonOrderDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Идентификатор заказа YandexMarket */
    #[Assert\NotBlank]
    private readonly string $number;

    /** Ответственный */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private readonly UserProfileUid $profile;

    /** Выделить заказ */
    private readonly bool $danger;

    /** Статус заказа */
    #[Assert\NotBlank]
    private OrderStatus $status;

    /** Комментарий к заказу */
    #[Assert\NotBlank]
    private readonly string $comment;


    public function __construct(array $order, UserProfile|UserProfileUid|string $profile)
    {
        if(is_string($profile))
        {
            $profile = new UserProfileUid($profile);
        }

        if($profile instanceof UserProfile)
        {
            $profile = $profile->getId();
        }

        $this->profile = $profile;

        $this->danger = true; // выделяем заказ

        $this->number = 'O-'.$order['posting_number'];

        $this->comment = sprintf('Ozon Seller: %s', $order['cancellation']['cancel_reason']);
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
     * Статус «Отмена» меняется в случае, если заказ «Новый» либо «Не оплаченный»
     * В остальных случаях отмена только в ручную, для этого заказ выделяется и обновляется комментарий
     */
    public function cancelOrder(): void
    {
        $this->status = new OrderStatus(OrderStatusCanceled::class);
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /**
     * Danger
     */
    public function getDanger(): bool
    {
        return $this->danger;
    }

    /** Профиль пользователя при неоплаченном статусе - NULL */
    public function getProfile(): UserProfileUid
    {
        return $this->profile;
    }


    /**
     * Number
     */
    public function getNumber(): string
    {
        return $this->number;
    }


    /**
     * Comment
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }
}

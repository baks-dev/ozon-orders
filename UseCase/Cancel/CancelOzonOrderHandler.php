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

namespace BaksDev\Ozon\Orders\UseCase\Cancel;


use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;

final class CancelOzonOrderHandler // extends AbstractHandler
{
    public function __construct(
        private OrderStatusHandler $orderStatusHandler,
        private CurrentOrderEventByNumberInterface $currentOrderEventByNumber,
    ) {}

    /** @see CancelOzonOrderDTO */
    public function handle(CancelOzonOrderDTO|OrderEventInterface $command): array|false
    {

        $orders = [];

        /** Получаем все отправления по номеру заказа */
        $results = $this->currentOrderEventByNumber->findAll($command->getOrderNumber());

        if(true === empty($results))
        {
            return false;
        }

        foreach($results as $OrderEvent)
        {
            $EditOrderDTO = new EditOrderDTO();
            $OrderEvent->getDto($EditOrderDTO);

            if(
                true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class)
                || true === $OrderEvent->isDanger()
            )
            {
                continue;
            }

            /**
             * Делаем отмену заказа
             */

            $OrderEvent->getDto($command);

            if(
                true === $OrderEvent->isStatusEquals(OrderStatusNew::class)
                || true === $OrderEvent->isStatusEquals(OrderStatusUnpaid::class)
            )
            {
                /** Автоматически отменяем «Новый» либо «Не оплаченный» заказ */
                $command->cancelOrder();
            }

            $orders[] = $this->orderStatusHandler->handle($command, false);
        }

        return $orders;
    }
}
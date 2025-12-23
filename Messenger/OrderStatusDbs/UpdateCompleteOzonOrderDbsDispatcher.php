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

namespace BaksDev\Ozon\Orders\Messenger\OrderStatusDbs;

use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersCompleteRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryDbsOzon;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обновляем заказ Озон DBS при готов к выдаче
 */
#[AsMessageHandler(priority: 8)]
final readonly class UpdateCompleteOzonOrderDbsDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private UpdateOzonOrdersCompleteRequest $UpdateOzonOrdersCompleteRequest,
        private OrderEventInterface $orderEventRepository,
    ) {}

    public function __invoke(OrderMessage $message): void
    {

        /** Активное событие заказа */
        $OrderEvent = $this->orderEventRepository
            ->find($message->getEvent());

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /** Завершаем обработчик, если статус заказа не Completed «Выполнен» */
        if(false === $OrderEvent->isStatusEquals(OrderStatusCompleted::class))
        {
            return;
        }

        /**
         * Завершаем обработчик если тип доставки заказа не Ozon Dbs «Доставка собственной службой логистики»
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsOzon::TYPE))
        {
            return;
        }

        if(empty($OrderEvent->getOrderTokenIdentifier()))
        {
            $this->Logger->warning(
                message: 'Токен авторизации в заказе не найден! Возможно заказ был создан Администратором.',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /** Токен из заказа в системе (был установлен при получении заказа из Ozon) */
        $OzonTokenUid = new OzonTokenUid($OrderEvent->getOrderTokenIdentifier());

        $isComplete = $this
            ->UpdateOzonOrdersCompleteRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->complete(order: $OrderEvent->getOrderNumber());

        if(true === $isComplete)
        {
            $this->Logger->info(
                message: sprintf('%s: Обновили статус заказа на «Доставлено»', $OrderEvent->getOrderNumber()),
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );
        }
    }
}

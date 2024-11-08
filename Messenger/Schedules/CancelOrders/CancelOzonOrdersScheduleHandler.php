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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\CancelOrders;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersByStatusRequest;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderHandler;
use DateInterval;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class CancelOzonOrdersScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly GetOzonOrdersByStatusRequest $GetOzonOrdersByStatusRequest,
        private readonly CancelOzonOrderHandler $CancelOzonOrderHandler,
        private readonly DeduplicatorInterface $deduplicator,
        LoggerInterface $ozonOrdersOrdersLogger,
    )
    {
        $this->logger = $ozonOrdersOrdersLogger;
    }

    public function __invoke(CancelOzonOrdersScheduleMessage $message): void
    {
        /** Получаем список ОТМЕНЕННЫХ сборочных заданий */
        $orders = $this->GetOzonOrdersByStatusRequest
            ->profile($message->getProfile())
            ->findAllCancel();

        /** @var CancelOzonOrderDTO $CancelOzonOrderDTO */
        foreach($orders as $CancelOzonOrderDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->deduplicator
                ->namespace('ozon-orders')
                ->expiresAfter(DateInterval::createFromDateString('1 day'))
                ->deduplication([
                    $CancelOzonOrderDTO->getNumber(),
                    self::class
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $handle = $this->CancelOzonOrderHandler->handle($CancelOzonOrderDTO);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Отменили заказ %s', $CancelOzonOrderDTO->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'profile' => (string) $CancelOzonOrderDTO->getProfile(),
                    ]
                );

                continue;
            }

            if($handle !== false)
            {
                $this->logger->critical(
                    sprintf('ozon-orders: Ошибка при отмене заказа %s', $CancelOzonOrderDTO->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'handle' => $handle,
                        'profile' => (string) $CancelOzonOrderDTO->getProfile(),
                    ]
                );
            }

            $Deduplicator->save();
        }
    }
}

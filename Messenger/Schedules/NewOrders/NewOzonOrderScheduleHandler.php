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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\NewOrders;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderHandler;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersNewRequest;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrdersNewRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\ToggleUnpaidToNewYaMarketOrderDTO;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewOzonOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly GetOzonOrdersNewRequest $getOzonOrdersNewRequest,
        private readonly YandexMarketOrderHandler $yandexMarketOrderHandler,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        LoggerInterface $ozonOrdersLogger,
    ) {
        $this->logger = $ozonOrdersLogger;
    }

    public function __invoke(NewOzonOrdersScheduleMessage $message): void
    {
        // TODO:
        return;

        /**
         * Получаем список НОВЫХ сборочных заданий по основному идентификатору компании
         */

        $orders = $this->getOzonOrdersNewRequest
            ->profile($message->getProfile())
            ->findAll();

        if($orders->valid())
        {
            $this->ordersCreate($orders);
        }

        /**
         * Получаем заказы по дополнительным идентификаторам
         */

        $extra = $this->tokenExtraCompany->profile($message->getProfile())->execute();

        if($extra !== false)
        {
            foreach($extra as $company)
            {
                $orders = $this->yandexMarketNewOrdersRequest
                    ->setExtraCompany($company['company'])
                    ->findAll();

                if($orders->valid())
                {
                    $this->ordersCreate($orders);
                }
            }
        }
    }

    private function ordersCreate(Generator $orders): void
    {
        /** @var YandexMarketOrderDTO $order */
        foreach($orders as $order)
        {
            $handle = $this->yandexMarketOrderHandler->handle($order);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Добавили новый заказ %s', $order->getNumber()),
                    [self::class.':'.__LINE__]
                );
            }
        }
    }
}

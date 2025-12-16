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

namespace BaksDev\Ozon\Orders\Messenger\SplitOrders;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\SplitOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Разделить заказ на отправления без сборки */
#[AsMessageHandler(priority: 0)]
final readonly class SplitOzonOrderDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $MessageDispatch,
        private GetOzonOrderInfoRequest $getOzonOrderInfoRequest,
        private SplitOzonOrdersPackageRequest $SplitOzonOrdersPackageRequest

    ) {}

    public function __invoke(SplitOzonOrderMessage $message): void
    {

        /** Дедубликатор по идентификатору продукта в заказе */
        $Deduplicator = $this->Deduplicator
            ->namespace('ozon-orders')
            ->deduplication(
                keys: [
                    (string) $message->getOrderNumber(),
                    self::class,
                ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        /**
         * Заказ из Ozon
         *
         * @var NewOzonOrderDTO $NewOzonOrderDTO
         */
        $NewOzonOrderDTO = $this->getOzonOrderInfoRequest
            ->forTokenIdentifier($message->getToken())
            ->find($message->getOrderNumber());

        if(false === ($NewOzonOrderDTO instanceof NewOzonOrderDTO))
        {
            $this->logger->critical(
                message: sprintf('ozon-orders: не удалось получить информацию о заказе %s',
                    $message->getOrderNumber(),
                ),
                context: [self::class.':'.__LINE__,],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: $message->getProfile().'-low',
            );

            return;
        }

        /**
         * Подсчет общего количества продукта в заказе
         *
         * @var NewOrderProductDTO $NewOrderProductDTO
         */

        $total = 0;

        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            /** Общее количество продукта в заказе */
            $total += $NewOrderProductDTO->getPrice()->getTotal();
        }

        /** Не пытаемся разделить заказ если он в количестве 1 шт */

        if($total === 1)
        {
            $Deduplicator->save();
            return;
        }

        $products = null;

        /**
         * @var NewOrderProductDTO $NewOrderProductDTO
         * Разделяем заказа на отдельные машиноместа
         */
        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            $pack = $NewOrderProductDTO->getPrice()->getTotal();

            for($i = 1; $i <= $pack; $i++)
            {
                $products[]['products'][] = [
                    "product_id" => $NewOrderProductDTO->getSku(),
                    "quantity" => 1, // всегда делим заказ на одно машиноместо
                ];
            }
        }

        if(empty($products))
        {
            $Deduplicator->save();
            return;
        }

        /**
         * Выполняем запрос на разделение
         */
        $isSplit = $this
            ->SplitOzonOrdersPackageRequest
            ->forTokenIdentifier($message->getToken())
            ->products($products)
            ->package($message->getOrderNumber());


        if(false === $isSplit)
        {
            $this->logger->critical(
                message: sprintf('ozon-orders: Ошибка при разделении заказа %s на отправления',
                    $message->getOrderNumber(),
                ),
                context: [self::class.':'.__LINE__,],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: $message->getProfile().'-low',
            );

            return;
        }

        $Deduplicator->save();
    }
}

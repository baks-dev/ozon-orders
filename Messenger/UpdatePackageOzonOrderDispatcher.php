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

namespace BaksDev\Ozon\Orders\Messenger;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\DeliveryTransport\Repository\ProductParameter\ProductParameter\ProductParameterInterface;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusPackage;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обновляем заказ Озон при отправке заказа на упаковку и разделяем заказ на машиноместо
 */
#[AsMessageHandler(priority: 8)]
final readonly class UpdatePackageOzonOrderDispatcher
{
    public function __construct(
        #[Target('ordersOrderLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $deduplicator,
        private OrderEventInterface $orderEventRepository,
        private UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest,
        private ProductParameterInterface $ProductParameterRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
    ) {}

    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                OrderStatusPackage::STATUS,
                self::class
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        $OrderEvent = $this->orderEventRepository->find($message->getEvent());

        if(false === $OrderEvent)
        {
            return;
        }

        if(empty($OrderEvent->getOrderNumber()))
        {
            $this->logger->warning(
                'Невозможно определить номер заказа (возможно изменилось событие)',
                [self::class.':'.__LINE__, 'OrderUid' => (string) $message->getId()]
            );

            return;
        }

        /**
         * Проверяем, что номер заказа начинается с O- (Озон)
         */
        if(false === str_starts_with($OrderEvent->getOrderNumber(), 'O-'))
        {
            return;
        }

        /**
         * Если статус заказа не Package «Упаковка заказов» - завершаем обработчик
         */
        if(false === $OrderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            return;
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);
        $OrderUserDTO = $EditOrderDTO->getUsr();

        if(!$OrderUserDTO)
        {
            return;
        }

        $EditOrderInvariableDTO = $EditOrderDTO->getInvariable();
        $UserProfileUid = $EditOrderInvariableDTO->getProfile();

        if(is_null($UserProfileUid))
        {
            return;
        }


        /** @var NewOzonOrderDTO $NewOzonOrderDTO */
        $NewOzonOrderDTO = $this->GetOzonOrderInfoRequest
            ->profile($UserProfileUid)
            ->find($OrderEvent->getOrderNumber());


        /** Общее количество в заказе */

        $total = 0;

        foreach($NewOzonOrderDTO->getProduct() as $totals)
        {
            $total += $totals->getPrice()->getTotal();
        }

        /** Разбиваем заказ на машиноместа */

        $products = null;

        foreach($NewOzonOrderDTO->getProduct() as $key => $OrderProductDTO)
        {
            /** Получаем идентификатор карточки Озон */

            $ProductData = $this->ProductConstByArticleRepository
                ->find($OrderProductDTO->getArticle());

            $ProductParameter = $this->ProductParameterRepository
                ->forProduct($ProductData->getProduct())
                ->forOfferConst($ProductData->getOfferConst())
                ->forVariationConst($ProductData->getVariationConst())
                ->forModificationConst($ProductData->getModificationConst())
                ->find();

            $package = $ProductParameter['package'] ?? 1;

            $pack = $OrderProductDTO->getPrice()->getTotal();

            for($i = 1; $i <= $pack; $i++)
            {
                if($total > $package)
                {
                    $products[]['products'][] = [
                        "product_id" => $OrderProductDTO->getSku(),
                        "quantity" => $package
                    ];
                }

                if($package >= $total)
                {
                    $products[]['products'][] = [
                        "product_id" => $OrderProductDTO->getSku(),
                        "quantity" => $total
                    ];
                }

                $total -= $package;

                if(0 >= $total)
                {
                    break;
                }
            }
        }

        $package = $this
            ->UpdateOzonOrdersPackageRequest
            ->profile($UserProfileUid)
            ->products($products)
            ->package($OrderEvent->getOrderNumber());

        if($package)
        {
            $this->logger->info(
                sprintf('%s: Отправили информацию о принятом в обработку заказе', $EditOrderInvariableDTO->getNumber()),
                [self::class.':'.__LINE__]
            );

            $Deduplicator->save();
        }

    }

}

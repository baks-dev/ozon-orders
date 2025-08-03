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
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\User\OrderUserDTO;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
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
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $deduplicator,
        private OrderEventInterface $OrderEventRepository,
        private CurrentOrderEventInterface $CurrentOrderEvent,
        private UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest,
        private ProductParameterInterface $ProductParameterRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
        private OzonTokensByProfileInterface $OzonTokensByProfile,
    ) {}

    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                self::class
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        $OrderEvent = $this->OrderEventRepository
            ->find($message->getEvent());

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->logger->critical(
                'ozon-orders: Не найдено событие OrderEvent',
                [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /**
         * Если статус заказа не Package «Упаковка заказов» - завершаем обработчик
         */
        if(false === $OrderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            return;
        }

        /**
         * Если тип доставки заказа не Ozon Fbs «Доставка службой Ozon» - завершаем обработчик
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::TYPE))
        {
            return;
        }


        /** Получаем активное событие заказа на случай, если изменилось и не возможно определить номер */
        if(false === ($OrderEvent->getOrderProfile() instanceof UserProfileUid))
        {
            $OrderEvent = $this->CurrentOrderEvent
                ->forOrder($message->getId())
                ->find();

            if(false === ($OrderEvent instanceof OrderEvent))
            {
                $this->logger->critical(
                    'ozon-orders: Не найдено событие OrderEvent',
                    [self::class.':'.__LINE__, var_export($message, true)],
                );

                return;
            }
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);
        $OrderUserDTO = $EditOrderDTO->getUsr();

        if(false === ($OrderUserDTO instanceof OrderUserDTO))
        {
            return;
        }

        $UserProfileUid = $OrderEvent->getOrderProfile();

        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $this->logger->critical(
                'ozon-orders: Невозможно определить идентификатор профиля склада заказа',
                [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }


        /** Получаем все токены профиля */

        $tokensByProfile = $this->OzonTokensByProfile
            ->findAll($UserProfileUid);

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        foreach($tokensByProfile as $OzonTokenUid)
        {
            /** @var NewOzonOrderDTO $NewOzonOrderDTO */
            $NewOzonOrderDTO = $this->GetOzonOrderInfoRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->find($OrderEvent->getOrderNumber());

            /** Пропускаем если заказ у токена не найден */
            if(false === ($NewOzonOrderDTO instanceof NewOzonOrderDTO))
            {
                continue;
            }

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
                            "quantity" => $package,
                        ];
                    }

                    if($package >= $total)
                    {
                        $products[]['products'][] = [
                            "product_id" => $OrderProductDTO->getSku(),
                            "quantity" => $total,
                        ];
                    }

                    $total -= $package;

                    if(0 >= $total)
                    {
                        break;
                    }
                }
            }

            if(empty($products))
            {
                $this->logger->critical(
                    'ozon-orders: Ошибка при попытке разбить заказ на несколько отправлений',
                    [self::class.':'.__LINE__, 'OrderUid' => (string) $message->getId()],
                );

                continue;
            }

            /**
             * Присваиваем упаковкам дополнительную информацию (номер ГТД и т.п.)
             */

            // TOTO ...

            /**
             * Разбиваем заказ на несколько упаковок
             */

            $package = $this
                ->UpdateOzonOrdersPackageRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->products($products)
                ->package($OrderEvent->getOrderNumber());

            if(true === $package)
            {
                $this->logger->info(
                    sprintf('%s: Отправили информацию о принятом в обработку заказе', $OrderEvent->getOrderNumber()),
                    [self::class.':'.__LINE__],
                );

                $Deduplicator->save();
            }
        }
    }
}

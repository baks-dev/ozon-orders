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

namespace BaksDev\Ozon\Orders\Messenger\Package;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\DeliveryTransport\Repository\ProductParameter\ProductParameter\ProductParameterInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Products\OrderProductDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Products\Posting\OrderProductPostingDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\User\OrderUserDTO;
use BaksDev\Orders\Order\UseCase\Admin\Posting\UpdateOrderProductsPostingHandler;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryDbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOzonOrderProductDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обновляем заказ Озон при отправке заказа на упаковку и разделяем заказ на машиноместо
 */
#[AsMessageHandler(priority: 8)]
final readonly class UpdatePackageOzonOrderDbsDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $MessageDispatch,
        private UpdateOzonOrdersPackageRequest $updateOzonOrdersPackageRequest,
        private GetOzonOrderInfoRequest $getOzonOrderInfoRequest,
        private OrderEventInterface $orderEventRepository,
        private CurrentOrderEventInterface $currentOrderEventRepository,
        private ProductConstByArticleInterface $productConstByArticleRepository,
        private UpdateOrderProductsPostingHandler $updateOrderProductsPostingHandler
    ) {}

    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

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

        /**
         * Завершаем обработчик если тип доставки заказа не Ozon Dbs «Доставка собственной службой логистики»
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsOzon::TYPE))
        {
            $Deduplicator->save();
            return;
        }

        /** Завершаем обработчик, если статус заказа не Package «Упаковка заказов» */
        if(false === $OrderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            return;
        }

        /** Идентификатор бизнес профиля (склада) */
        $UserProfileUid = $OrderEvent->getOrderProfile();

        /** Получаем активное событие заказа на случай, если оно изменилось и не возможно определить номер */
        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $OrderEvent = $this->currentOrderEventRepository
                ->forOrder($message->getId())
                ->find();

            if(false === ($OrderEvent instanceof OrderEvent))
            {
                $this->Logger->critical(
                    message: 'ozon-orders: Не найдено событие OrderEvent',
                    context: [self::class.':'.__LINE__, var_export($message, true)],
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

        if(false === ($OrderEvent->getOrderProfile() instanceof UserProfileUid))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Невозможно определить идентификатор профиля склада заказа',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

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


        /**
         * Заказ из Ozon
         *
         * @var NewOzonOrderDTO $NewOzonOrderDTO
         */
        $NewOzonOrderDTO = $this->getOzonOrderInfoRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->find($OrderEvent->getOrderNumber());

        if(false === ($NewOzonOrderDTO instanceof NewOzonOrderDTO))
        {
            $this->Logger->critical(
                message: sprintf('ozon-orders: не удалось получить информацию о заказе %s',
                    $OrderEvent->getOrderNumber(),
                ),
                context: [
                    'OrderUid' => (string) $OrderEvent->getMain(),
                    self::class.':'.__LINE__,
                ],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('3 seconds')],
                transport: $UserProfileUid.'-low',
            );

            return;
        }

        $package = null;

        /**
         * @var NewOzonOrderProductDTO $NewOrderProductDTO
         */
        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            /** Получаем идентификатор карточки в системе */
            $ProductData = $this->productConstByArticleRepository
                ->find($NewOrderProductDTO->getArticle());

            if(false === $ProductData)
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: для продукта арт. %s не найдена карточка',
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        'OrderUid' => (string) $OrderEvent->getMain(),
                        self::class.':'.__LINE__,
                    ],
                );

                return;
            }

            /** Дедубликатор по идентификатору продукта в заказе */
            $DeduplicatorOrderProduct = $this->Deduplicator
                ->namespace('ozon-orders')
                ->deduplication(
                    keys: [
                        (string) $message->getId(),
                        (string) $ProductData->getEvent(),
                        (string) $ProductData->getOffer(),
                        (string) $ProductData->getVariation(),
                        (string) $ProductData->getModification(),
                        self::class,
                    ]);

            if($DeduplicatorOrderProduct->isExecuted() === true)
            {
                continue;
            }

            /** Добавляем продукт для упаковки */
            $package[] = [
                "product_id" => $NewOrderProductDTO->getSku(),
                "quantity" => $NewOrderProductDTO->getPrice()->getTotal(),
            ];


            /**
             * Из всех продуктов в заказе в системе находим соответствие продукту из заказа Ozon
             *
             * @var OrderProductDTO|null $orderProductDTO
             * @var CurrentProductByBarcodeResult $ProductData
             */

            $orderProductDTO = $EditOrderDTO->getProduct()
                ->findFirst(function($k, OrderProductDTO $orderProductElement) use ($ProductData) {

                    return
                        $orderProductElement->getProduct()->equals($ProductData->getEvent())
                        && ((is_null($orderProductElement->getOffer()) === true && is_null($ProductData->getOffer()) === true) || $orderProductElement->getOffer()?->equals($ProductData->getOffer()))
                        && ((is_null($orderProductElement->getVariation()) === true && is_null($ProductData->getVariation()) === true) || $orderProductElement->getVariation()?->equals($ProductData->getVariation()))
                        && ((is_null($orderProductElement->getModification()) === true && is_null($ProductData->getModification()) === true) || $orderProductElement->getModification()?->equals($ProductData->getModification()));
                });

            if(null === $orderProductDTO)
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: Не найдено соответствия продукта в системном заказе продукту из заказа Ozon арт. %s',
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        'OrderUid' => (string) $OrderEvent->getMain(),
                        self::class.':'.__LINE__,
                    ],
                );

                return;
            }

            /**
             * Присваиваем и сохраняем в качестве отправления номер заказа
             */
            $posting = new OrderProductPostingDTO;
            $posting->setNumber($OrderEvent->getOrderNumber());
            $orderProductDTO->addPosting($posting);

            $OrderProduct = $this->updateOrderProductsPostingHandler->handle($orderProductDTO);

            if(false === ($OrderProduct instanceof OrderProduct))
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: Ошибка %s при сохранении отправления.
                    Сохраните отправления вручную: product %s, posting_numbers: %s',
                        $OrderProduct,
                        $orderProductDTO->getOrderProductId(),
                        $OrderEvent->getOrderNumber(),
                    ),
                    context: [
                        'OrderUid' => (string) $OrderEvent->getMain(),
                        var_export($message, true),
                        self::class.':'.__LINE__,
                    ],
                );

                return;
            }
        }

        if(empty($package))
        {
            $this->Logger->critical(
                message: sprintf('ozon-orders: Ошибка при упаковке заказа %s', $OrderEvent->getOrderNumber()),
                context: [
                    'OrderUid' => (string) $OrderEvent->getMain(),
                    self::class.':'.__LINE__,
                ],
            );

            return;
        }


        /**
         * Отправляем в упаковку заказ
         */

        $products[]['products'] = $package;

        $postingPackages = $this
            ->updateOzonOrdersPackageRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->products($products)
            ->package($OrderEvent->getOrderNumber());

        if(false === $postingPackages)
        {
            $this->Logger->critical(
                message: sprintf('ozon-orders: Ошибка при упаковке заказа %s',
                    $OrderEvent->getOrderNumber(),
                ),
                context: [
                    'OrderUid' => (string) $OrderEvent->getMain(),
                    self::class.':'.__LINE__,
                ],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('3 seconds')],
                transport: $UserProfileUid.'-low',
            );
        }
    }
}

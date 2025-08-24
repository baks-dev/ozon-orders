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
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\ProcessOzonPackageStickersMessage;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductDTO;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обновляем заказ Озон при отправке заказа на упаковку и разделяем заказ на машиноместо
 */
#[AsMessageHandler(priority: 8)]
final readonly class UpdatePackageOzonOrderFbsDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $MessageDispatch,
        private UpdateOzonOrdersPackageRequest $updateOzonOrdersPackageRequest,
        private GetOzonOrderInfoRequest $getOzonOrderInfoRequest,
        private OrderEventInterface $orderEventRepository,
        private CurrentOrderEventInterface $currentOrderEventRepository,
        private ProductParameterInterface $productParameterRepository,
        private ProductConstByArticleInterface $productConstByArticleRepository,
        private UpdateOrderProductsPostingHandler $updateOrderProductsPostingHandler
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

        /** Завершаем обработчик, если статус заказа не Package «Упаковка заказов» */
        if(false === $OrderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            return;
        }

        /**
         * Завершаем обработчик если тип доставки заказа не Ozon Fbs «Доставка службой Ozon»
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::TYPE))
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
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('3 seconds')],
                transport: $UserProfileUid.'-low',
            );

            return;
        }


        /**
         * @var NewOrderProductDTO $NewOrderProductDTO
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
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
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

            /** Находим параметры упаковки продукта */
            $DeliveryPackageParameters = $this->productParameterRepository
                ->forProduct($ProductData->getProduct())
                ->forOfferConst($ProductData->getOfferConst())
                ->forVariationConst($ProductData->getVariationConst())
                ->forModificationConst($ProductData->getModificationConst())
                ->find();

            if(false === $DeliveryPackageParameters)
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: У продукта арт. %s отсутствуют параметры упаковки',
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                return;
            }

            /** Общее количество продукта в заказе */
            $total = $NewOrderProductDTO->getPrice()->getTotal();

            /** Машиноместо для продукта */
            $package = $DeliveryPackageParameters['package'] ?? 1;

            /** Количество одного продукта в заказе */
            $pack = $NewOrderProductDTO->getPrice()->getTotal();

            $products = $this->packing($package, $total, $pack, $NewOrderProductDTO->getSku());

            if(null === $products)
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: Ошибка при попытке разбить заказ %s на несколько отправлений',
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                return;
            }

            /**
             * Из всех продуктов в заказе в системе находим соответствие продукту из заказа Ozon
             *
             * @var OrderProductDTO|null $orderProductDTO
             * @var CurrentProductDTO $ProductData
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
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                return;
            }

            /** Делаем запрос на разделение */
            $postingPackages = $this
                ->updateOzonOrdersPackageRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->products($products)
                ->package($OrderEvent->getOrderNumber());

            /**
             * Получаем массив идентификаторов разделенных отправлений
             */

            if(false === $postingPackages)
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: заказ %s с продуктом арт: %s не удалось разделить на отправления',
                        $OrderEvent->getOrderNumber(),
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                $this->MessageDispatch->dispatch(
                    message: $message,
                    stamps: [new MessageDelay('3 seconds')],
                    transport: $UserProfileUid.'-low',
                );

                return;
            }

            /** Если заказ УЖЕ БЫЛ РАЗДЕЛЕН, но в БД не сохранен */
            if(true === $postingPackages)
            {
                $this->Logger->warning(
                    message: sprintf('ozon-orders: заказ %s с продуктом арт: %s был разделен, но отправления не сохранены',
                        $OrderEvent->getOrderNumber(),
                        $NewOrderProductDTO->getArticle(),
                    ),
                    context: [
                        self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                return;
            }

            /** Если заказ успешно разделился на отправления */
            if(true === is_array($postingPackages))
            {
                $this->Logger->info(
                    message: sprintf('ozon-orders: заказ %s с продуктом арт: %s разделен на отправления: %s',
                        $OrderEvent->getOrderNumber(),
                        $NewOrderProductDTO->getArticle(),
                        implode(' ', $postingPackages),
                    ),
                    context: [self::class.':'.__LINE__],
                );

                $DeduplicatorOrderProduct->save();
            }

            /** Каждый номер отправления добавляем в коллекцию отправлений */
            foreach($postingPackages as $postingNumber)
            {
                $posting = new OrderProductPostingDTO;
                $posting->setValue($postingNumber);

                $orderProductDTO->addPosting($posting);


                /**
                 *  На каждый номер отправления бросаем сообщение для скачивания стикера OZON
                 *
                 * @var OrderProductPostingDTO $OrderProductPostingDTO
                 */

                $ProcessOzonPackageStickersMessage = new ProcessOzonPackageStickersMessage(
                    token: $OzonTokenUid,
                    postingNumber: $postingNumber,
                );

                $this->MessageDispatch->dispatch(
                    message: $ProcessOzonPackageStickersMessage,
                    stamps: [new MessageDelay('5 seconds')],
                    transport: 'ozon-orders',
                );
            }

            /**
             * Сохраняем коллекцию отправлений для заказа
             */
            $OrderProduct = $this->updateOrderProductsPostingHandler->handle($orderProductDTO);

            if(false === ($OrderProduct instanceof OrderProduct))
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: Ошибка %s при сохранении коллекции отправлений.
                    Сохраните отправления вручную: product %s, posting_numbers: %s',
                        $OrderProduct,
                        $orderProductDTO->getOrderProductId(),
                        implode(' ', $postingPackages),
                    ),
                    context: [
                        $message, self::class.':'.__LINE__,
                        var_export($OrderEvent->getId(), true),
                    ],
                );

                return;
            }

        }
    }

    /**
     * Формирует массив с отправлениями для разделения заказа на отправления
     */
    public function packing(int $package, int $total, int $pack, int $sku): ?array
    {
        $products = null;

        for($i = 1; $i <= $pack; $i++)
        {

            if($total > $package)
            {
                $products[]['products'][] = [
                    "product_id" => $sku,
                    "quantity" => $package,
                ];
            }

            if($package >= $total)
            {
                $products[]['products'][] = [
                    "product_id" => $sku,
                    "quantity" => $total,
                ];
            }

            $total -= $package;

            /** Если $total равен 0 или отрицательное значение - прерываем */
            if(0 >= $total)
            {
                break;
            }

        }

        return $products;
    }
}

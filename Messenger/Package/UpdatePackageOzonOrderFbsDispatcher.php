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
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageDTO;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Messenger\Schedules\NewOrders\NewOzonOrderScheduleHandler;
use BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers\Create\CreateTaskOzonStickersMessage;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductDTO;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обновляем заказ Озон при отправке заказа на упаковку и разделяем заказ на машиноместо
 */
#[AsMessageHandler(priority: 8)]
#[Autoconfigure(shared: false)]
final class UpdatePackageOzonOrderFbsDispatcher
{
    /** Общее количество продуктов в заказе  */
    private int $total;

    /** Массив отправлений для разделения в Ozon */
    private array|null $products;

    /** Уникальные в заказе */
    private array|null $orderProducts;

    /** Продукты для добавления отправлений */
    private array|null $postingProducts;

    public function __construct(
        #[Target('ozonOrdersLogger')] private readonly LoggerInterface $Logger,
        private readonly DeduplicatorInterface $Deduplicator,
        private readonly MessageDispatchInterface $MessageDispatch,
        private readonly UpdateOzonOrdersPackageRequest $updateOzonOrdersPackageRequest,
        private readonly GetOzonOrderInfoRequest $getOzonOrderInfoRequest,
        private readonly OrderEventInterface $orderEventRepository,
        private readonly CurrentOrderEventInterface $currentOrderEventRepository,
        private readonly ProductParameterInterface $productParameterRepository,
        private readonly ProductConstByArticleInterface $productConstByArticleRepository,
        private readonly UpdateOrderProductsPostingHandler $updateOrderProductsPostingHandler,
        private readonly CurrentProductIdentifierInterface $CurrentProductIdentifierRepository,

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


        /**
         * Создаем блокировку на получение новых заказов (чтобы не прилетали дубликаты)
         */

        $DeduplicatorOrdersNew = $this->Deduplicator
            ->namespace('ozon-orders')
            ->expiresAfter('10 seconds')
            ->deduplication([(string) $UserProfileUid, NewOzonOrderScheduleHandler::class]);

        $DeduplicatorOrdersNew->save();

        /**
         * Получаем активное событие заказа на случай, если оно изменилось и не возможно определить номер
         */

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
         * ПОДСЧЕТ ОБЗЕГО КОЛИЧЕСТВА ТОВАРОВ В ЗАКАЗЕ
         */

        /**
         * @var NewOrderProductDTO $NewOrderProductDTO
         */

        $this->total = 0;

        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            /** Общее количество продукта в заказе */
            $this->total += $NewOrderProductDTO->getPrice()->getTotal();
        }

        /**
         * РАЗДЕЛЕНИЕ НА УПАКОВКИ
         */

        /**
         * @var NewOrderProductDTO $NewOrderProductDTO
         */

        $this->products = null;
        $this->orderProducts = null;
        $this->postingProducts = null;

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

            /**
             * Из всех продуктов в заказе в системе находим соответствие продукту из заказа Ozon
             *
             * @var OrderProductDTO|null $orderProductDTO
             * @var CurrentProductDTO $ProductData
             */

            $orderProductDTO = $EditOrderDTO->getProduct()
                ->findFirst(function($k, OrderProductDTO $orderProductElement) use ($ProductData) {

                    $CurrentProductIdentifierResult = $this->CurrentProductIdentifierRepository
                        ->forEvent($orderProductElement->getProduct())
                        ->forOffer($orderProductElement->getOffer())
                        ->forVariation($orderProductElement->getVariation())
                        ->forModification($orderProductElement->getModification())
                        ->find();

                    return
                        $CurrentProductIdentifierResult->getEvent()->equals($ProductData->getEvent())
                        && ((is_null($CurrentProductIdentifierResult->getOfferConst()) === true && is_null($ProductData->getOfferConst()) === true) || $CurrentProductIdentifierResult->getOfferConst()->equals($ProductData->getOfferConst()))
                        && ((is_null($CurrentProductIdentifierResult->getVariationConst()) === true && is_null($ProductData->getVariationConst()) === true) || $CurrentProductIdentifierResult->getVariationConst()->equals($ProductData->getVariationConst()))
                        && ((is_null($CurrentProductIdentifierResult->getModificationConst()) === true && is_null($ProductData->getModificationConst()) === true) || $CurrentProductIdentifierResult->getModificationConst()->equals($ProductData->getModificationConst()));
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

            /** Идентификатор на каждый продукт в заказе */
            $orderProductId = (string) $orderProductDTO->getOrderProductId();

            /** Продукт из заказа */
            $this->orderProducts[$orderProductId] = $orderProductDTO;

            /** Продукт для отправлений */
            $this->postingProducts[$orderProductId] = $NewOrderProductDTO->getSku();

            /** Машиноместо для продукта */
            $package = $DeliveryPackageParameters['package'] ?? 1;

            /** Количество одного продукта в заказе */
            $pack = $NewOrderProductDTO->getPrice()->getTotal();

            $this->packing($package, $pack, $NewOrderProductDTO->getSku());

            if(null === $this->products)
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

        }

        /**
         * ОТПРАВКА РАЗДЕЛЕННЫХ УПАКОВОК НА OZON
         */

        /**
         * Делаем запрос на разделение
         *
         * @var $postings UpdateOzonOrdersPackageDTO|false
         */
        $postings = $this
            ->updateOzonOrdersPackageRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->products($this->products)
            ->package($OrderEvent->getOrderNumber());


        if(false === $postings)
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
        if(true === $postings)
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
        if(true === ($postings instanceof UpdateOzonOrdersPackageDTO))
        {
            $this->Logger->info(
                message: sprintf('ozon-orders: заказ %s с продуктом арт: %s разделен на отправления: %s',
                    $OrderEvent->getOrderNumber(),
                    $NewOrderProductDTO->getArticle(),
                    implode(' ', $postings->getResult()),
                ),
                context: [
                    self::class.':'.__LINE__,
                    var_export($postings, true),
                ],
            );

            $DeduplicatorOrderProduct->save();
        }


        /**
         * СОХРАНЯЕМ НОМЕРА ОТПРАВЛЕНИЙ, ПОЛУЧЕННЫХ ИЗ OZON
         */

        /**
         * @var OrderProductDTO $OrderProductDTO
         */
        foreach($this->orderProducts as $OrderProductDTO)
        {
            /** SKU по номеру продукта из заказа */
            $orderSku = $this->postingProducts[(string) $OrderProductDTO->getOrderProductId()];

            /** Отправления для конкретного продукта */
            $postingsForOrder = array_filter($postings->getAdditionalData(),
                static function(array $posting) use ($orderSku) {

                    $product = current($posting['products']);

                    return $product['sku'] === $orderSku;
                });

            if(true === empty($postingsForOrder))
            {
                $this->Logger->critical(
                    message: sprintf(
                        'ozon-orders: Дополнительная информация об отправлениях не получена: SKU - %s, orderProduct - %s.',
                        $orderSku,
                        $OrderProductDTO->getOrderProductId(),
                    ),
                    context: [
                        $postings->getAdditionalData(),
                        $this->postingProducts,
                        (string) $OrderProductDTO->getOrderProductId(),
                        self::class.':'.__LINE__,
                    ],
                );

                continue;
            }

            /** Сохраняем для продукта его отправления */
            foreach($postingsForOrder as $key => $postingInfo)
            {
                $postingNumber = $postingInfo['posting_number'];

                $posting = new OrderProductPostingDTO;
                $posting->setNumber($postingNumber);

                $OrderProductDTO->addPosting($posting);

                /**
                 * На каждый номер отправления - бросаем сообщение для скачивания стикера OZON
                 */

                $CreateTaskOzonStickersMessage = new CreateTaskOzonStickersMessage(
                    $OzonTokenUid,
                    $postingNumber,
                );

                $this->MessageDispatch->dispatch(
                    message: $CreateTaskOzonStickersMessage,
                    stamps: [new MessageDelay(sprintf('%s seconds', ($key + 10)))],
                    transport: 'ozon-orders',
                );
            }

            $OrderProduct = $this->updateOrderProductsPostingHandler->handle($OrderProductDTO);

            if(false === ($OrderProduct instanceof OrderProduct))
            {
                $this->Logger->critical(
                    message: sprintf('ozon-orders: Ошибка %s при сохранении коллекции отправлений.
                    Сохраните отправления вручную: product %s, posting_numbers: %s',
                        $OrderProduct,
                        $OrderProductDTO->getOrderProductId(),
                        implode(' ', $postings->getResult()),
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
    public function packing(int $package, int $pack, int $sku): void
    {
        for($i = 1; $i <= $pack; $i++)
        {

            if($this->total > $package)
            {
                $this->products[]['products'][] = [
                    "product_id" => $sku,
                    "quantity" => $package,
                ];
            }

            if($package >= $this->total)
            {
                $this->products[]['products'][] = [
                    "product_id" => $sku,
                    "quantity" => $this->total,
                ];
            }

            $this->total -= $package;

            /** Если $total равен 0 или отрицательное значение - прерываем */
            if(0 >= $this->total)
            {
                break;
            }

        }
    }
}

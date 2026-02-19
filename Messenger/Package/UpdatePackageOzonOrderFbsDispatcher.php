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
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageDTO;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers\Create\CreateTaskOzonStickersMessage;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierByEventInterface;
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
    private int $total = 0;

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
        private readonly CurrentProductIdentifierByEventInterface $CurrentProductIdentifierRepository,
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
         * Завершаем обработчик если тип доставки заказа НЕ!!! Ozon Fbs «Доставка службой Ozon»
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::TYPE))
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


        //        /**
        //         * Создаем блокировку на получение новых заказов (чтобы не прилетали дубликаты)
        //         */
        //
        //        $DeduplicatorOrdersNew = $this->Deduplicator
        //            ->namespace('ozon-orders')
        //            ->expiresAfter('10 seconds')
        //            ->deduplication([(string) $UserProfileUid, NewOzonOrderScheduleHandler::class]);
        //
        //        $DeduplicatorOrdersNew->save();

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
            ->find($OrderEvent->getPostingNumber());

        if(false === ($NewOzonOrderDTO instanceof NewOzonOrderDTO))
        {
            $this->Logger->critical(
                message: sprintf('ozon-orders: не удалось получить информацию о заказе %s',
                    $OrderEvent->getPostingNumber(),
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


        if($NewOzonOrderDTO->getProduct()->count() > 1)
        {
            $this->Logger->critical(
                sprintf(
                    'ozon-orders: Ошибка при упаковке заказа! Заказ %s FBS Ozon должен состоять из одного продукта',
                    $OrderEvent->getOrderNumber(),
                ),
                [self::class.':'.__LINE__],
            );

            return;
        }

        $NewOrderProductDTO = $NewOzonOrderDTO->getProduct()->current();

        if($NewOrderProductDTO->getPrice()->getTotal() > 1)
        {
            $this->Logger->critical(
                sprintf(
                    'ozon-orders: Ошибка при упаковке заказа! Продукт в заказе %s FBS Ozon должен состоять из одной единицы',
                    $OrderEvent->getOrderNumber(),
                ),
                [self::class.':'.__LINE__],
            );

            return;
        }

        /**
         * Отправляем заказ на упаковку
         */

        $package[] = [
            'products' => [
                [
                    "product_id" => $NewOrderProductDTO->getSku(),
                    "quantity" => 1,
                ],
            ],
        ];

        /** Делаем запрос отправку заказа в доставку */
        $UpdateOzonOrdersPackageDTO = $this
            ->updateOzonOrdersPackageRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->products($package)
            ->package($OrderEvent->getPostingNumber());

        /** Если возвращается TRUE - не отправляем больше запросы */
        if(true === $UpdateOzonOrdersPackageDTO)
        {
            $Deduplicator->save();
            return;
        }


        if(false === ($UpdateOzonOrdersPackageDTO instanceof UpdateOzonOrdersPackageDTO))
        {
            $this->Logger->critical(
                message: sprintf('ozon-orders: Пробуем позже отправить заказ %s с продуктом арт: %s на упаковку',
                    $OrderEvent->getPostingNumber(),
                    $NewOrderProductDTO->getArticle(),
                ),
                context: [
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: $UserProfileUid.'-low',
            );

            return;
        }


        /**
         * Бросаем сообщение для скачивания стикера OZON
         */

        $CreateTaskOzonStickersMessage = new CreateTaskOzonStickersMessage(
            $OzonTokenUid,
            $OrderEvent->getPostingNumber(),
        );

        $this->MessageDispatch->dispatch(
            message: $CreateTaskOzonStickersMessage,
            stamps: [new MessageDelay('10 seconds')],
            transport: 'ozon-orders',
        );

    }
}

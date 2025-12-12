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

namespace BaksDev\Ozon\Orders\Messenger\WaitingShipment;


use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Ozon\Orders\Api\Exemplar\GetOzonOrdersExemplarStatusRequest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageDTO;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\Messenger\ExemplarInfo\UpdateOrderExemplarInfoMessage;
use BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers\Create\CreateTaskOzonStickersMessage;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Отправляем заказ в статус «Ожидают отгрузки» */
#[AsMessageHandler(priority: 0)]
final readonly class WaitingShipmentDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
        private UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest,
        private GetOzonOrdersExemplarStatusRequest $GetOzonOrdersExemplarStatusRequest,
        private MessageDispatchInterface $MessageDispatch,
    ) {}

    /**
     * Отправляем заказ в статус «Ожидают отгрузки»
     */
    public function __invoke(WaitingShipmentMessage $message): void
    {
        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getOrderId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        if(false === ($OrderEvent->getOrderProfile() instanceof UserProfileUid))
        {
            $this->logger->critical(
                message: 'ozon-orders: Невозможно определить идентификатор профиля склада заказа',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /** Токен из заказа в системе (был установлен при получении заказа из Ozon) */
        $OzonTokenUid = new OzonTokenUid($OrderEvent->getOrderTokenIdentifier());


        /**
         * Проверяем, что заказ может быть отправлен в «Ожидают отгрузки»
         */

        $isExemplar = $this
            ->GetOzonOrdersExemplarStatusRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->find($OrderEvent->getOrderNumber());

        /** Если $exemplar не равен TRUE - заказ требует указать доп. информацию */
        if(true !== $isExemplar)
        {
            $UpdateOrderExemplarInfoMessage = new UpdateOrderExemplarInfoMessage($message->getOrderId());

            $this->MessageDispatch->dispatch(
                message: $UpdateOrderExemplarInfoMessage,
                transport: $OrderEvent->getOrderProfile(),
            );

            return;
        }

        /**
         * Получаем информацию о заказе
         *
         * @var NewOzonOrderDTO $NewOzonOrderDTO
         */
        $NewOzonOrderDTO = $this->GetOzonOrderInfoRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->find($OrderEvent->getOrderNumber());

        if(false === ($NewOzonOrderDTO instanceof NewOzonOrderDTO))
        {
            $this->logger->critical(
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
                stamps: [new MessageDelay('5 seconds')],
                transport: $OrderEvent->getOrderProfile().'-low',
            );

            return;
        }


        /** Создаем правила упаковки заказа */
        $package = null;

        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            /** Добавляем продукт для упаковки */
            $package[] = [
                'products' => [
                    [
                        "product_id" => $NewOrderProductDTO->getSku(),
                        "quantity" => $NewOrderProductDTO->getPrice()->getTotal(),
                    ],
                ],
            ];
        }

        if(empty($package))
        {
            $this->logger->critical(
                message: sprintf(
                    'ozon-orders: заказ %s не удалось разделить на отправления',
                    $OrderEvent->getOrderNumber(),
                ),
                context: [
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
            );

            return;
        }


        /**
         * Делаем запрос на отправку заказа в «Ожидают отгрузки»
         *
         * @var $postings UpdateOzonOrdersPackageDTO|false
         */
        $postings = $this
            ->UpdateOzonOrdersPackageRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->products($package)
            ->package($OrderEvent->getOrderNumber());


        if(false === $postings)
        {
            $this->logger->critical(
                message: sprintf(
                    'ozon-orders: заказ %s не удалось разделить на отправления',
                    $OrderEvent->getOrderNumber(),
                ),
                context: [
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
            );

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: $OrderEvent->getOrderProfile().'-low',
            );

            return;
        }

        /**
         * Бросаем отложенное сообщение для скачивания стикера OZON
         */

        $CreateTaskOzonStickersMessage = new CreateTaskOzonStickersMessage(
            $OzonTokenUid,
            $OrderEvent->getOrderNumber(),
        );

        $this->MessageDispatch->dispatch(
            message: $CreateTaskOzonStickersMessage,
            stamps: [new MessageDelay('10 seconds')],
            transport: 'ozon-orders',
        );

    }
}

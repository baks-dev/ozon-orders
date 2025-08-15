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

namespace BaksDev\Ozon\Orders\Messenger\GetOzonPackageStickers;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\User\OrderUserDTO;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\ProcessOzonPackageStickersMessage;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получаем все отправления одного заказа и запускаем процесс получения стикеров Ozon
 * next @see ProcessOzonPackageStickersDispatcher
 */
#[AsMessageHandler(priority: 0)]
final readonly class GetOzonPackageStickersDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $MessageDispatch,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private GetOzonOrderInfoRequest $getOzonOrderInfoRequest,
    ) {}

    public function __invoke(OrderMessage $message): void
    {
        $Deduplicator = $this->Deduplicator
            ->namespace('ozon-orders')
            ->deduplication(
                keys: [
                    $message->getId(),
                    self::class,
                ]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            return;
        }

        if(false === $OrderEvent->isStatusEquals(OrderStatusPackage::class))
        {
            return;
        }

        $EditOrderDTO = $OrderEvent->getDto(EditOrderDTO::class);

        if(false === ($EditOrderDTO->getUsr() instanceof OrderUserDTO))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Невозможно определить идентификатор пользователя заказа',
                context: [
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
            );

            return;
        }

        $UserProfileUid = $OrderEvent->getOrderProfile();

        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Невозможно определить идентификатор профиля склада заказа',
                context: [
                    self::class.':'.__LINE__,
                    var_export($OrderEvent->getId(), true),
                ],
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
            ->find($EditOrderDTO->getInvariable()->getNumber());

        /** Массив всех отправлений заказа */
        $postings = array_merge($NewOzonOrderDTO->getRelatedPostings(), [$EditOrderDTO->getInvariable()->getNumber()]);

        /**
         * На каждый номер отправления бросаем сообщение для скачивания стикера OZON
         */

        foreach($postings as $posting)
        {
            $ProcessOzonPackageStickersMessage = new ProcessOzonPackageStickersMessage(
                token: $OzonTokenUid,
                postingNumber: $posting,
            );

            $this->MessageDispatch->dispatch(
                message: $ProcessOzonPackageStickersMessage,
                stamps: [new MessageDelay('5 seconds')],
                transport: 'ozon-orders',
            );
        }

        $Deduplicator->save();
    }
}
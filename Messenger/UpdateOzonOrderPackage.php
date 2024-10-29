<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusPackage;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardIdentifierRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class UpdateOzonOrderPackage
{
    private LoggerInterface $logger;

    public function __construct(
        #[Autowire(env: 'APP_ENV')] private readonly string $environment,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly OrderEventInterface $orderEventRepository,
        private readonly GetOzonCardIdentifierRequest $GetOzonCardIdentifierRequest,
        private readonly UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest,
        LoggerInterface $ordersOrderLogger,
    )
    {
        $this->logger = $ordersOrderLogger;
    }

    /**
     * Обновляем заказ Озон при отправке заказа на упаковку
     * Разделяем заказ на машиноместо
     */
    public function __invoke(OrderMessage $message): void
    {

        return;

        if($this->environment !== 'prod')
        {
            return;
        }

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
            return;
        }

        /** Проверяем, что номер заказа начинается с O- (Озон) */
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


        foreach($EditOrderDTO->getProduct() as $product)
        {
            /** Получаем идентификатор карточки Озон */

            $this
                ->GetOzonCardIdentifierRequest
                ->profile($UserProfileUid)
                ->article('АРТИКУЛ')
                ->find();

            dump($product);
        }


        //        $this
        //            ->UpdateOzonOrdersPackageRequest
        //            ->profile($UserProfileUid)
        //            ->package();


        $this->logger->info(
            sprintf('%s: Отправили информацию о принятом в обработку заказе', $EditOrderInvariableDTO->getNumber()),
            [self::class.':'.__LINE__]
        );

        $Deduplicator->save();
    }

}

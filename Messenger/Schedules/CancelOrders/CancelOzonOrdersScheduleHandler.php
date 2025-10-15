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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\CancelOrders;


use BaksDev\Centrifugo\Server\Publish\CentrifugoPublishInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersByStatusRequest;
use BaksDev\Ozon\Orders\Schedule\CancelOrders\CancelOrdersSchedule;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderHandler;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final readonly class CancelOzonOrdersScheduleHandler
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private GetOzonOrdersByStatusRequest $GetOzonOrdersByStatusRequest,
        private CancelOzonOrderHandler $CancelOzonOrderHandler,
        private DeduplicatorInterface $Deduplicator,
        private OzonTokensByProfileInterface $OzonTokensByProfileRepository,
        private CentrifugoPublishInterface $publish,
    ) {}

    public function __invoke(CancelOzonOrdersScheduleMessage $message): void
    {
        /**
         * Ограничиваем периодичность запросов
         */

        $DeduplicatorExec = $this->Deduplicator
            ->namespace('ozon-orders')
            ->expiresAfter(CancelOrdersSchedule::INTERVAL)
            ->deduplication([
                (string) $message->getProfile(),
                self::class,
            ]);

        if($DeduplicatorExec->isExecuted())
        {
            return;
        }

        /* @see строку :194 */
        $DeduplicatorExec->save();

        /** Получаем все токены профиля */
        $tokensByProfile = $this->OzonTokensByProfileRepository
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            $DeduplicatorExec->delete();

            return;
        }

        foreach($tokensByProfile as $OzonTokenUid)
        {
            $this->logger->info(
                sprintf('%s: Получаем список ОТМЕНЕННЫХ сборочных заданий', $OzonTokenUid),
                [self::class.':'.__LINE__],
            );

            /**
             * Получаем список ОТМЕНЕННЫХ сборочных заданий
             */

            $orders = $this->GetOzonOrdersByStatusRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->interval($message->getInterval())
                ->findAllCancel();


            /** @var CancelOzonOrderDTO $CancelOzonOrderDTO */
            foreach($orders as $CancelOzonOrderDTO)
            {
                /** Индекс дедубдикации по номеру отправления */
                $Deduplicator = $this->Deduplicator
                    ->namespace('ozon-orders')
                    ->expiresAfter('1 day')
                    ->deduplication([
                        $CancelOzonOrderDTO->getPostingNumber(),
                        self::class,
                    ]);

                // Если передан интервал - не проверяем дедубликатор
                if(is_null($message->getInterval()) && $Deduplicator->isExecuted())
                {
                    $this->logger->info(
                        sprintf('%s: Заказ уже отменен', $CancelOzonOrderDTO->getPostingNumber()),
                        [
                            self::class.':'.__LINE__,
                            'token' => (string) $OzonTokenUid,
                            var_export($message, true),
                        ],
                    );

                    continue;
                }

                $Order = $this->CancelOzonOrderHandler->handle($CancelOzonOrderDTO);

                if($Order instanceof Order)
                {
                    $this->logger->info(
                        sprintf('Отменили заказ %s', $CancelOzonOrderDTO->getPostingNumber()),
                        [
                            self::class.':'.__LINE__,
                            'token' => (string) $OzonTokenUid,
                        ],
                    );

                    /** Скрываем идентификатор у всех пользователей */
                    $this->publish
                        ->addData(['profile' => false]) // Скрывает у всех
                        ->addData(['identifier' => (string) $Order->getId()])
                        ->send('remove');

                    $Deduplicator->save();

                    continue;
                }

                if($Order !== false)
                {
                    $this->logger->critical(
                        sprintf('ozon-orders: Ошибка при отмене заказа %s', $CancelOzonOrderDTO->getPostingNumber()),
                        [
                            self::class.':'.__LINE__,
                            'handle' => $Order,
                            'token' => (string) $OzonTokenUid,
                        ],
                    );
                }
            }
        }

        $DeduplicatorExec->delete();
    }
}

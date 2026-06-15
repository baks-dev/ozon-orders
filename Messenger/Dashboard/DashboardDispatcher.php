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

namespace BaksDev\Ozon\Orders\Messenger\Dashboard;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Finances\Entity\Event\FinancesEvent;
use BaksDev\Finances\Messenger\Default\FinancesMessage;
use BaksDev\Finances\Repository\CurrentFinancesEvent\CurrentFinancesEventInterface;
use BaksDev\Finances\Repository\Statistics\Orders\StatisticsOrdersInterface;
use BaksDev\Finances\Repository\Statistics\Orders\StatisticsOrdersResult;
use BaksDev\Finances\Repository\Statistics\Orders\Tests\StatisticsOrdersInterfaceTest;
use BaksDev\Ozon\Orders\Messenger\Dashboard\CacheAll\DashboardCacheAllDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\CacheOrders\DashboardCacheOrdersDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\CacheOthers\DashboardCacheOthersDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\HoldAll\DashboardHoldAllDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\HoldOrders\DashboardHoldOrdersDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\HoldOthers\DashboardHoldOthersDayMessage;
use BaksDev\Ozon\Orders\Messenger\Dashboard\OrderFinance\DashboardOthersFinancesMessage;
use BaksDev\Users\User\Type\Id\UserUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Обновляет финансовые выплаты */
#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class DashboardDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private readonly MessageDispatchInterface $dispatch,
        private readonly DeduplicatorInterface $Deduplicator,
        private readonly ?CurrentFinancesEventInterface $CurrentFinancesEventRepository = null,
    ) {}

    public function __invoke(FinancesMessage $message): void
    {
        if(false === $this->CurrentFinancesEventRepository instanceof CurrentFinancesEventInterface)
        {
            return;
        }

        /** Получаем информацию о платеже */

        $FinancesEvent = $this->CurrentFinancesEventRepository
            ->forFinanceMain($message->getId())
            ->find();

        if(false === ($FinancesEvent instanceof FinancesEvent))
        {
            $this->logger->critical(
                'finances: Ошибка при получении финансовой выплаты',
                [self::class.':'.__LINE__],
            );

            return;
        }

        if(
            false === $FinancesEvent->isInvariable()
            || false === $FinancesEvent->isPayment()
        )
        {
            $this->logger->critical(
                'finances: Ошибка при получении финансовой выплаты',
                [
                    self::class.':'.__LINE__,
                    'invariable' => $FinancesEvent->isInvariable(),
                    'payment' => $FinancesEvent->isPayment(),
                ],
            );

            return;
        }

        $UserUid = $FinancesEvent->getInvariable()->getUserFinance();

        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('finances')
            ->expiresAfter('1 hour')
            ->deduplication([
                (string) $UserUid,
                $FinancesEvent->isOrders(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        if(true === $FinancesEvent->isOrders())
        {
            /**
             * Создаем доску "Выплаты по заказам"
             */

            $DashboardCacheOrdersDayMessage = new DashboardCacheOrdersDayMessage(
                payment: $FinancesEvent->getPayment(),
                user: $UserUid,
            );

            $this->dispatch->dispatch(
                message: $DashboardCacheOrdersDayMessage,
                stamps: [new MessageDelay('30 minutes')],
                transport: 'finances',
            );


            /**
             * Создаем доску "Удержано по заказам"
             */

            $DashboardHoldOrdersDayMessage = new DashboardHoldOrdersDayMessage(
                payment: $FinancesEvent->getPayment(),
                user: $UserUid,
            );

            $this->dispatch->dispatch(
                message: $DashboardHoldOrdersDayMessage,
                stamps: [new MessageDelay('31 minutes')],
                transport: 'finances',
            );


            /**
             * Создаем доску финансовой выгоды по заказам
             */

            $DashboardOthersFinancesMessage = new DashboardOthersFinancesMessage(
                payment: $FinancesEvent->getPayment(),
                user: $UserUid,
                date: $FinancesEvent->getDateCreated(),
            );

            $this->dispatch->dispatch(
                message: $DashboardOthersFinancesMessage,
                stamps: [new MessageDelay('32 minutes')],
                transport: 'finances',
            );
        }

        if(false === $FinancesEvent->isOrders())
        {
            /**
             * Создаем доску "Прочие выплаты"
             */

            $DashboardCacheOthersDayMessage = new DashboardCacheOthersDayMessage(
                payment: $FinancesEvent->getPayment(),
                user: $UserUid,
            );

            $this->dispatch->dispatch(
                message: $DashboardCacheOthersDayMessage,
                stamps: [new MessageDelay('35 minutes')],
                transport: 'finances',
            );

            /**
             * Создаем доску "Прочих удержаний"
             */

            $DashboardHoldOthersDayMessage = new DashboardHoldOthersDayMessage(
                payment: $FinancesEvent->getPayment(),
                user: $UserUid,
            );

            $this->dispatch->dispatch(
                message: $DashboardHoldOthersDayMessage,
                stamps: [new MessageDelay('36 minutes')],
                transport: 'finances',
            );
        }


        /**
         * Создаем доску "Итого выплат"
         */

        $DashboardCacheAllDayMessage = new DashboardCacheAllDayMessage(
            payment: $FinancesEvent->getPayment(),
            user: $UserUid,
        );

        $this->dispatch->dispatch(
            message: $DashboardCacheAllDayMessage,
            stamps: [new MessageDelay('40 minutes')],
            transport: 'finances',
        );


        /**
         * Создаем доску "Всего удержано"
         */

        $DashboardHoldAllDayMessage = new DashboardHoldAllDayMessage(
            payment: $FinancesEvent->getPayment(),
            user: $UserUid,
        );

        $this->dispatch->dispatch(
            message: $DashboardHoldAllDayMessage,
            stamps: [new MessageDelay('41 minutes')],
            transport: 'finances',
        );
    }
}

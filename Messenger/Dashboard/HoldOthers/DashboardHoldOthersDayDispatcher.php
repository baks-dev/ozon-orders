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

namespace BaksDev\Ozon\Orders\Messenger\Dashboard\HoldOthers;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Dashboard\Entity\Dashboard;
use BaksDev\Dashboard\Entity\Event\DashboardEvent;
use BaksDev\Dashboard\Repository\DashboardCurrentEventByPeriod\DashboardCurrentEventByPeriodInterface;
use BaksDev\Dashboard\UseCase\NewEdit\NewEditDashboardDTO;
use BaksDev\Dashboard\UseCase\NewEdit\NewEditDashboardHandler;
use BaksDev\Dashboard\UseCase\NewEdit\Type\NewEditDashboardTypeDTO;
use BaksDev\Finances\Repository\CurrentFinancesEvent\CurrentFinancesEventInterface;
use BaksDev\Finances\Repository\Statistics\Orders\StatisticsOrdersInterface;
use BaksDev\Finances\Repository\Statistics\Orders\StatisticsOrdersResult;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\User\Type\Id\UserUid;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class DashboardHoldOthersDayDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private readonly DeduplicatorInterface $Deduplicator,
        private readonly StatisticsOrdersInterface $StatisticsOrdersRepository,
        private readonly NewEditDashboardHandler $NewEditDashboardHandler,
        private readonly DashboardCurrentEventByPeriodInterface $DashboardCurrentEventByPeriodRepository,
    ) {}

    public function __invoke(DashboardHoldOthersDayMessage $message): void
    {

        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('ozon-orders')
            ->expiresAfter('1 hour')
            ->deduplication([
                $message,
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        /** Получаем положительные транзакции по заказу за сутки */

        $dayFrom = new DateTimeImmutable('now')->sub(DateInterval::createFromDateString('1 day'));
        $dayTo = new DateTimeImmutable('now')->sub(DateInterval::createFromDateString('1 day'));

        $StatisticsOrdersResult = $this
            ->StatisticsOrdersRepository
            ->forUser($message->getUser())
            ->forPayment($message->getPayment())
            //->onlyOrders() // только транзакции по заказу
            ->onlyNotOrders() // только транзакции не имеющие заказы
            ->onlyHold() // только отрицательный баланс
            ->dayFrom($dayFrom)
            ->dayTo($dayTo)
            ->find();

        if(false === ($StatisticsOrdersResult instanceof StatisticsOrdersResult))
        {
            return;
        }


        /** Создаем Dashboard на вчерашний день, чтобы отобразить сегодня */

        // получаем имеющийся Dashboard


        /** @see DashboardDTO */
        $NewEditDashboardDTO = new NewEditDashboardDTO();

        $DashboardEvent = $this->DashboardCurrentEventByPeriodRepository
            ->user($message->getUser())
            ->payment($message->getPayment())
            ->period($dayFrom, $dayTo)
            ->type('hold_others_day')
            ->find();

        if(true === $DashboardEvent instanceof DashboardEvent)
        {
            $DashboardEvent->getDto($NewEditDashboardDTO);
        }

        /** Создаем новый объект */
        if(false === $DashboardEvent instanceof DashboardEvent)
        {
            $DashboardInvariableDTO = $NewEditDashboardDTO->getInvariable();
            $DashboardInvariableDTO
                ->setName('Прочих удержаний')
                ->setPeriod($dayFrom, $dayTo)
                ->setPriority(85);

            $NewEditDashboardTypeDTO = $NewEditDashboardDTO->getType();
            $NewEditDashboardTypeDTO->setValue('hold_others_day');

            $DashboardPaymentDTO = $NewEditDashboardDTO->getPayment();
            $DashboardPaymentDTO->setValue($message->getPayment());

            $DashboardUserDTO = $NewEditDashboardDTO->getUser();
            $DashboardUserDTO->setValue($message->getUser());
        }

        $NewEditDashboardDTO->setTotal($StatisticsOrdersResult->getTotal());

        $Dashboard = $this->NewEditDashboardHandler->handle($NewEditDashboardDTO);

        if(false === ($Dashboard instanceof Dashboard))
        {
            $this->logger->critical(
                'finances: Ошибка при создании Dashboard "Выплаты по заказам"',
                [self::class.':'.__LINE__],
            );

            return;
        }

        $Deduplicator->save();
    }
}

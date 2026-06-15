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

namespace BaksDev\Ozon\Orders\Messenger\Dashboard\OrderFinance;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Dashboard\Entity\Dashboard;
use BaksDev\Dashboard\Entity\Event\DashboardEvent;
use BaksDev\Dashboard\Repository\DashboardCurrentEventByPeriod\DashboardCurrentEventByPeriodInterface;
use BaksDev\Dashboard\UseCase\NewEdit\NewEditDashboardDTO;
use BaksDev\Dashboard\UseCase\NewEdit\NewEditDashboardHandler;
use BaksDev\Finances\Repository\Statistics\Finance\OrderFinanceInterface;
use BaksDev\Finances\Repository\Statistics\Finance\OrderFinanceResult;
use BaksDev\Finances\Repository\Statistics\Orders\StatisticsOrdersInterface;
use BaksDev\Reference\Money\Type\Money;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class DashboardOthersFinancesDispatcher
{

    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private readonly DeduplicatorInterface $Deduplicator,
        private readonly OrderFinanceInterface $OrderFinanceInterface,
        private readonly NewEditDashboardHandler $NewEditDashboardHandler,
        private readonly DashboardCurrentEventByPeriodInterface $DashboardCurrentEventByPeriodRepository,
    ) {}

    public function __invoke(DashboardOthersFinancesMessage $message): void
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

        $dayFrom = $message->getDate(); // начало дня
        $dayTo = $message->getDate(); // окончание дня

        $orders = $this->OrderFinanceInterface
            ->forUser($message->getUser())
            ->forPayment($message->getPayment())
            ->dayFrom($dayFrom) // присвоит начало дня
            ->dayTo($dayTo) // присвоит окончание дня
            ->findAll();

        if(false === $orders || false === $orders->valid())
        {
            return;
        }

        $total = 0;

        foreach($orders as $OrderFinanceResult)
        {
            $total += $OrderFinanceResult->getOrderPrice();
            $total -= $OrderFinanceResult->getOrderFinance();
        }

        /** Создаем Dashboard на вчерашний день, чтобы отобразить сегодня */

        /** @see DashboardDTO */
        $NewEditDashboardDTO = new NewEditDashboardDTO();

        $DashboardEvent = $this->DashboardCurrentEventByPeriodRepository
            ->user($message->getUser())
            ->payment($message->getPayment())
            ->period($dayFrom, $dayTo)
            ->type('order_finance_day')
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
                ->setName('Резервный фонд')
                ->setPeriod($dayFrom, $dayTo)
                ->setPriority(90);

            $NewEditDashboardTypeDTO = $NewEditDashboardDTO->getType();
            $NewEditDashboardTypeDTO->setValue('order_finance_day');

            $DashboardPaymentDTO = $NewEditDashboardDTO->getPayment();
            $DashboardPaymentDTO->setValue($message->getPayment());

            $DashboardUserDTO = $NewEditDashboardDTO->getUser();
            $DashboardUserDTO->setValue($message->getUser());
        }

        $NewEditDashboardDTO->setTotal(new Money($total, true));

        $Dashboard = $this->NewEditDashboardHandler->handle($NewEditDashboardDTO);

        if(false === ($Dashboard instanceof Dashboard))
        {
            $this->logger->critical(
                'finances: Ошибка при создании Dashboard "Финансовая выгода"',
                [self::class.':'.__LINE__],
            );

            return;
        }

        $Deduplicator->save();
    }
}

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

namespace BaksDev\Ozon\Orders\Repository\OzonDashboard;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Dashboard\Entity\Dashboard;
use BaksDev\Dashboard\Entity\Event\DashboardEvent;
use BaksDev\Dashboard\Entity\Event\Invariable\DashboardInvariable;
use BaksDev\Dashboard\Entity\Event\Payment\DashboardPayment;
use BaksDev\Dashboard\Entity\Event\Type\DashboardType;
use BaksDev\Dashboard\Entity\Event\User\DashboardUser;
use BaksDev\Finances\Entity\Event\Invariable\FinancesInvariable;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFbsOzon;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileTokenStorage\UserProfileTokenStorageInterface;
use BaksDev\Users\User\Repository\UserTokenStorage\UserTokenStorageInterface;
use BaksDev\Users\User\Type\Id\UserUid;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Generator;


final class OzonDashboardRepository implements OzonDashboardInterface
{
    private DateTimeImmutable $day_from;
    private DateTimeImmutable $day_to;

    public function __construct(
        private readonly DBALQueryBuilder $DBALQueryBuilder,
        private readonly UserTokenStorageInterface $UserTokenStorage,
    ) {}

    /** Дата начала периода */
    public function dayStart(DateTimeImmutable $day): self
    {
        $this->day_from = $day->setTime(0, 0, 0);
        return $this;
    }

    /** Дата окончания периода */
    public function dayFinish(DateTimeImmutable $day): self
    {
        $this->day_to = $day->setTime(0, 0, 0);
        return $this;
    }

    /**
     * Метод возвращает информацию на доску dashboard
     *
     * @return Generator<OzonDashboardResult>|bool
     */
    public function findAll(): Generator|bool
    {
        $dbal = $this->DBALQueryBuilder->createQueryBuilder(self::class);

        $dbal->from(Dashboard::class, 'dashboard');

        $dbal
            ->addSelect('dashboard_invariable.name')
            ->join(
                'dashboard',
                DashboardInvariable::class,
                'dashboard_invariable',
                '
            dashboard_invariable.main = dashboard.id
            AND dashboard_invariable.start = :date_from 
            AND dashboard_invariable.finish = :date_to
            ',
            )
            ->setParameter('date_from', $this->day_from, Types::DATE_IMMUTABLE)
            ->setParameter('date_to', $this->day_to, Types::DATE_IMMUTABLE);


        $dbal->join(
            'dashboard',
            DashboardUser::class,
            'dashboard_user',
            '
                dashboard_user.main = dashboard.id 
                AND dashboard_user.value = :usr
            ')
            ->setParameter(
                key: 'usr',
                value: $this->UserTokenStorage->getUser(),
                type: UserUid::TYPE,
            );

        $dbal->join(
            'dashboard',
            DashboardPayment::class,
            'dashboard_payment',
            '
                dashboard_payment.main = dashboard.id 
                AND dashboard_payment.value = :payment
            ',
        )
            ->setParameter(
                key: 'payment',
                value: new PaymentUid(TypePaymentFbsOzon::TYPE),
                type: PaymentUid::TYPE,
            );


        $dbal
            ->addSelect('dashboard_event.total')
            ->join(
                'dashboard',
                DashboardEvent::class,
                'dashboard_event',
                'dashboard_event.id = dashboard.event');


        $dbal
            ->addSelect('dashboard_type.value AS type')
            ->leftJoin(
                'dashboard',
                DashboardType::class,
                'dashboard_type',
                'dashboard_type.main = dashboard.id',
            );


        $dbal->orderBy('dashboard_invariable.priority', 'DESC');

        $result = $dbal
            ->enableCache('dashboard', '1 day')
            ->fetchAllHydrate(OzonDashboardResult::class);


        return $result->valid() ? $result : false;
    }
}
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

namespace BaksDev\Ozon\Orders\Schedule\NewOrdersFbo;

use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Messenger\Schedules\NewOrdersFbo\NewOzonOrdersFboScheduleMessage;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Обновляем заказы Ozon FBO
 *
 * #hourly - в какую-то минуту каждый час
 *
 * @see https://symfony.com/doc/current/scheduler.html#cron-expression-triggers
 * @see FinanceOzonOrdersScheduleDispatcher
 */
#[AsCronTask('#hourly', jitter: 60)]
final readonly class NewOzonOrdersFboCron
{
    public function __construct(
        #[Target('ozonProductsLogger')] private LoggerInterface $logger,
        private MessageDispatchInterface $messageDispatch,
        #[Autowire(env: 'PROJECT_PROFILE')] private string|null $profile = null,
    ) {}

    public function __invoke(): void
    {
        if(empty($this->profile))
        {
            return;
        }

        $this->logger->warning(
            sprintf('%s: Получаем заказы Ozon FBO', $this->profile),
            [__FILE__.':'.__LINE__],
        );

        $this->messageDispatch->dispatch(
            message: new NewOzonOrdersFboScheduleMessage(new UserProfileUid($this->profile)),
            stamps: [new MessageDelay('30 minutes')],
            transport: 'finances',
        );
    }
}
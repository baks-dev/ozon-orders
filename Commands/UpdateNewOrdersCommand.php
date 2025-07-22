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

namespace BaksDev\Ozon\Orders\Commands;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Messenger\Schedules\NewOrders\NewOzonOrdersScheduleMessage;
use BaksDev\Ozon\Repository\AllProfileToken\AllProfileOzonTokenInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateInterval;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'baks:ozon-orders:new',
    description: 'Получает новые заказы Оzon'
)]
class UpdateNewOrdersCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly AllProfileOzonTokenInterface $AllProfileOzonToken,
        private readonly MessageDispatchInterface $messageDispatch
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        /** Получаем активные токены авторизации профилей Yandex Market */
        $profiles = $this->AllProfileOzonToken
            ->onlyActiveToken()
            ->findAll();

        $profiles = iterator_to_array($profiles);

        $helper = $this->getHelper('question');


        /**
         * Интерактивная форма списка профилей
         */

        $questions[] = 'Все';

        foreach($profiles as $quest)
        {
            $questions[] = $quest->getAttr();
        }

        $questions['+'] = 'Выполнить все асинхронно';
        $questions['-'] = 'Выйти';

        $question = new ChoiceQuestion(
            'Профиль пользователя (Ctrl+C чтобы выйти)',
            $questions,
            '0',
        );

        $key = $helper->ask($input, $output, $question);

        /**
         *  Выходим без выполненного запроса
         */

        if($key === '-' || $key === 'Выйти')
        {
            return Command::SUCCESS;
        }


        /**
         * Выполняем все с возможностью асинхронно в очереди
         */

        if($key === '+' || $key === '0' || $key === 'Все')
        {
            /** @var UserProfileUid $profile */
            foreach($profiles as $profile)
            {
                $this->update($profile, $key === '+');
            }

            $this->io->success('Заказы успешно обновлены');
            return Command::SUCCESS;
        }


        /**
         * Выполняем определенный профиль
         */

        $UserProfileUid = null;

        foreach($profiles as $profile)
        {
            if($profile->getAttr() === $questions[$key])
            {
                /* Присваиваем профиль пользователя */
                $UserProfileUid = $profile;
                break;
            }
        }


        if($UserProfileUid)
        {
            $this->update($UserProfileUid);

            $this->io->success('Заказы успешно обновлены');
            return Command::SUCCESS;
        }

        $this->io->success('Профиль пользователя не найден');
        return Command::SUCCESS;

    }

    public function update(UserProfileUid $profile, bool $async = false): void
    {
        $this->io->note(sprintf('Обновляем новые заказы профиля %s', $profile->getAttr()));

        $NewOzonOrdersScheduleMessage = new NewOzonOrdersScheduleMessage($profile);
        $NewOzonOrdersScheduleMessage->setInterval(DateInterval::createFromDateString('1 day'));

        $this->messageDispatch
            ->dispatch(
                message: $NewOzonOrdersScheduleMessage,
                transport: $async === true ? (string) $profile : null,
            );
    }
}

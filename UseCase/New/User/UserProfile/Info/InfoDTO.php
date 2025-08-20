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

namespace BaksDev\Ozon\Orders\UseCase\New\User\UserProfile\Info;

use BaksDev\Users\Profile\UserProfile\Entity\Event\Info\UserProfileInfoInterface;
use BaksDev\Users\Profile\UserProfile\Type\UserProfileStatus\Status\UserProfileStatusBlock;
use BaksDev\Users\Profile\UserProfile\Type\UserProfileStatus\Status\UserProfileStatusModeration;
use BaksDev\Users\Profile\UserProfile\Type\UserProfileStatus\UserProfileStatus;
use BaksDev\Users\User\Entity\User;
use BaksDev\Users\User\Type\Id\UserUid;

/** @see UserProfileInfo */
final class InfoDTO implements UserProfileInfoInterface
{
    /** Пользователь, кому принадлежит профиль */
    private readonly UserUid $usr;

    /** Ссылка на профиль пользователя */
    private string $url;

    /** Текущий активный профиль, выбранный пользователем */
    private bool $active = false;

    /** Статус профиля (модерация, активен, заблокирован) */
    private UserProfileStatus $status;


    public function __construct()
    {
        $this->status = new UserProfileStatus(UserProfileStatusModeration::class);
        $this->url = uniqid(false, false);

    }

    /** Пользователь, кому принадлежит профиль */

    public function getUsr(): UserUid
    {
        return $this->usr;
    }


    public function setUsr(UserUid|User $usr): self
    {
        $this->usr = $usr instanceof User ? $usr->getId() : $usr;
        return $this;
    }


    /** Статус профиля (модерация) */

    public function getStatus(): UserProfileStatus
    {
        return $this->status;
    }

    public function setStatus(mixed $status): self
    {
        $this->status = new UserProfileStatus($status);

        return $this;
    }


    /**
     * @return bool
     */
    public function getActive(): bool
    {
        return $this->active;
    }


    /* URL */

    public function getUrl(): string
    {
        return $this->url;
    }


    public function isModeration(): bool
    {
        return $this->status->equals(UserProfileStatusModeration::class);
    }


    public function isBlock(): bool
    {
        return $this->status->equals(UserProfileStatusBlock::class);
    }

}

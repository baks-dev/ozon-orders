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

namespace BaksDev\Ozon\Orders\UseCase\New\User\UserAccount;

use BaksDev\Auth\Email\Entity\Event\AccountEventInterface;
use BaksDev\Auth\Email\Type\Email\AccountEmail;
use BaksDev\Auth\Email\Type\Event\AccountEventUid;
use Symfony\Component\Validator\Constraints as Assert;

final class NewOzonUserAccountDTO implements AccountEventInterface
{
    /** UserEvent ID */
    #[Assert\IsNull]
    private ?AccountEventUid $id;

    /** Email */
    #[Assert\NotBlank]
    #[Assert\Email]
    private AccountEmail $email;

    /** Дайджест Пароля */
    private string $password;


    /** Пароль */
    #[Assert\NotBlank]
    #[Assert\Length(
        min: 8,
        max: 4096
    )]
    private readonly string $passwordPlain;

    #[Assert\Valid]
    private Status\NewOzonUserAccountStatusDTO $status;


    public function __construct()
    {
        $this->id = null;
        $this->status = new Status\NewOzonUserAccountStatusDTO();

    }


    public function setId(AccountEventUid $id): void {}


    public function getEvent(): ?AccountEventUid
    {
        return $this->id;
    }


    /** Email */

    public function getEmail(): AccountEmail
    {
        return $this->email;
    }


    public function setEmail(AccountEmail $email): self
    {
        $this->email = $email;
        return $this;
    }

    /** Статус */


    public function getStatus(): Status\NewOzonUserAccountStatusDTO
    {
        return $this->status;
    }


    /** Текстовый пароль */

    public function getPasswordPlain(): ?string
    {
        return $this->passwordPlain;
    }


    public function setPasswordPlain(?string $passwordPlain): self
    {
        $this->passwordPlain = $passwordPlain;
        return $this;
    }


    /** Хешированный пароль */

    public function getPassword(): ?string
    {
        return $this->password;
    }


    public function setPasswordHash(string $password): self
    {
        $this->password = $password;
        return $this;
    }


}

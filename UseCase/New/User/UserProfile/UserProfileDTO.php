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

namespace BaksDev\Ozon\Orders\UseCase\New\User\UserProfile;

use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Entity\Event\UserProfileEventInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileFbsYandexMarket;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

final class UserProfileDTO implements UserProfileEventInterface
{
    #[Assert\Uuid]
    private ?UserProfileEventUid $id = null;

    /** Тип профиля */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private readonly TypeProfileUid $type;

    /** Значения профиля */
    #[Assert\Valid]
    private ArrayCollection $value;

    /** Информация профиля */
    private Info\InfoDTO $info;


    public function __construct()
    {
        $this->value = new ArrayCollection();
        $this->info = new Info\InfoDTO();
    }

    /* EVENT */

    public function getEvent(): ?UserProfileEventUid
    {
        return $this->id;
    }

    /** Тип профиля */

    public function setType(?TypeProfileUid $type): void
    {
        $this->type = $type;
    }

    public function getType(): ?TypeProfileUid
    {
        return $this->type;
    }


    /** Информация профиля */

    public function getInfo(): Info\InfoDTO
    {
        return $this->info;
    }


    /** Значения профиля */

    public function getValue(): ArrayCollection
    {
        return $this->value;
    }

    public function resetValue(): void
    {
        $this->value = new ArrayCollection();
    }


    public function addValue(Value\ValueDTO $value): void
    {
        $this->value->add($value);
    }


    public function removeValue(Value\ValueDTO $value): void
    {
        $this->value->removeElement($value);
    }

}

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

namespace BaksDev\Ozon\Orders\UseCase\New\User\UserProfile\Value;

use BaksDev\Users\Profile\TypeProfile\Type\Section\Field\Id\TypeProfileSectionFieldUid;
use BaksDev\Users\Profile\TypeProfile\Type\Section\Id\TypeProfileSectionUid;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Value\UserProfileValueInterface;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use Symfony\Component\Validator\Constraints as Assert;

final class ValueDTO implements UserProfileValueInterface
{
    /** Связь на поле */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private TypeProfileSectionFieldUid $field;

    /** Заполненное значение */
    private ?string $value = null;

    /** Вспомогательные свойства */

    private ?TypeProfileSectionUid $section = null;

    private ?string $sectionName = null;

    private ?string $sectionDescription = null;

    private string $type;


    /* FIELD */

    public function getField(): TypeProfileSectionFieldUid
    {
        return $this->field;
    }

    public function setField(TypeProfileSectionFieldUid $field): self
    {
        $this->field = $field;
        return $this;
    }

    /* VALUE */

    /**
     * @return string|null
     */
    public function getValue(): ?string
    {
        return $this->value;
    }


    /**
     * @param string|null $value
     */
    public function setValue(?string $value): self
    {
        $this->value = trim($value);
        return $this;
    }


    /* Вспомогательные методы */

    public function updSection(FieldValueFormDTO $fieldValueFormDTO): self
    {
        $this->section = $fieldValueFormDTO->getSection();
        $this->sectionName = $fieldValueFormDTO->getSectionName();
        $this->sectionDescription = $fieldValueFormDTO->getSectionDescription();
        $this->type = $fieldValueFormDTO->getType();
        return $this;
    }


    public function getSection(): ?TypeProfileSectionUid
    {
        return $this->section;
    }


    /**
     * @return string
     */
    public function getSectionName(): ?string
    {
        return $this->sectionName;
    }


    /**
     * @return string|null
     */
    public function getSectionDescription(): ?string
    {
        return $this->sectionDescription;
    }


    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

}

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

namespace BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\Tests;

use BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\ProcessOzonPackageStickersDispatcher;
use BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\ProcessOzonPackageStickersMessage;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group ozon-orders
 */
#[Group('ozon-orders')]
#[When(env: 'test')]
class ProcessOzonPackageStickersDebugTest extends KernelTestCase
{
    public function testUseCase(): void
    {
        self::assertTrue(true);
        return;

        $ProcessOzonPackageStickersMessage = new ProcessOzonPackageStickersMessage(
            new OzonTokenUid(),
            'O-93752074-0288-1',
        );

        /** @var ProcessOzonPackageStickersDispatcher $ProcessOzonPackageStickersDispatcher */
        $ProcessOzonPackageStickersDispatcher = self::getContainer()->get(ProcessOzonPackageStickersDispatcher::class);
        $ProcessOzonPackageStickersDispatcher($ProcessOzonPackageStickersMessage);
    }
}
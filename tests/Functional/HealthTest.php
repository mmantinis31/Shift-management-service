<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testApplicationRespondsWithJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertSame(['status' => 'ok'], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }
}

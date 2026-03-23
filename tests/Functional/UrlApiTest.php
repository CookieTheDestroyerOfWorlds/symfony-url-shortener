<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ShortUrl;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UrlApiTest extends WebTestCase
{
    private static bool $schemaReady = false;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        // createClient boots the test kernel (APP_ENV=test, framework.test=true)
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');

        if (!self::$schemaReady) {
            $tool = new SchemaTool($em);
            $meta = $em->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($meta);
            $tool->createSchema($meta);
            self::$schemaReady = true;
        }

        $em->getConnection()->executeStatement('TRUNCATE TABLE short_urls RESTART IDENTITY CASCADE');
    }

    public function testCreateShortUrlReturns201(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://example.com',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('shortCode', $data);
        $this->assertArrayHasKey('shortUrl', $data);
        $this->assertSame('https://example.com', $data['originalUrl']);
        $this->assertSame(0, $data['clickCount']);
        $this->assertTrue($data['isActive']);
    }

    public function testCreateWithCustomAlias(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://symfony.com',
            'customAlias' => 'symfony',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('symfony', $data['shortCode']);
    }

    public function testCreateWithDuplicateAliasReturns409(): void
    {
        $payload = json_encode(['url' => 'https://example.com', 'customAlias' => 'dup']);

        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateValidationReturns422(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'not-a-url',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('url', $data['errors']);
    }

    public function testStatsEndpointReturnsDetails(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://example.com',
            'customAlias' => 'stats-test',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/urls/stats-test');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('stats-test', $data['shortCode']);
        $this->assertSame('https://example.com', $data['originalUrl']);
    }

    public function testStatsReturns404ForUnknownCode(): void
    {
        $this->client->request('GET', '/api/urls/nonexistent');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRedirectFollowsToOriginalUrl(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://php.net',
            'customAlias' => 'php',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/php');
        $this->assertResponseStatusCodeSame(301);
        $this->assertResponseRedirects('https://php.net');
    }

    public function testRedirectIncrementsClickCount(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://php.net',
            'customAlias' => 'clicks',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/clicks');
        $this->client->request('GET', '/clicks');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $em->clear();

        $this->client->request('GET', '/api/urls/clicks');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['clickCount']);
    }

    public function testDeactivateReturns204AndBlocksRedirect(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://example.com',
            'customAlias' => 'bye',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/urls/bye');
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/bye');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRedirectReturns404ForUnknownCode(): void
    {
        $this->client->request('GET', '/does-not-exist');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExpiredUrlReturns410(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $url = new ShortUrl('expired', 'https://example.com');
        $url->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $em->persist($url);
        $em->flush();
        $em->clear();

        $this->client->request('GET', '/expired');

        $this->assertResponseStatusCodeSame(410);
    }

    public function testDeactivateAlreadyInactiveReturns409(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://example.com',
            'customAlias' => 'once',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/urls/once');
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/urls/once');
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateResponseIncludesRateLimitHeaders(): void
    {
        $this->client->request('POST', '/api/urls', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'url' => 'https://example.com',
        ]));
        $this->assertResponseStatusCodeSame(201);
        $response = $this->client->getResponse();
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    public function testHealthEndpoint(): void
    {
        $this->client->request('GET', '/health');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);
    }

    public function testReadinessEndpoint(): void
    {
        $this->client->request('GET', '/ready');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertTrue($data['checks']['database']);
        $this->assertTrue($data['checks']['cache']);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateShortUrlRequest;
use App\Entity\ShortUrl;
use App\Repository\ShortUrlRepository;
use App\Service\ShortUrlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class UrlController extends AbstractController
{
    public function __construct(
        private readonly ShortUrlService $shortUrlService,
        private readonly ShortUrlRepository $repository,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $createShortUrlLimiter,
    ) {
    }

    #[Route('/urls', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $limiter = $this->createShortUrlLimiter->create($request->getClientIp());
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            return $this->json(
                ['error' => 'Too many requests. Please slow down.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'X-RateLimit-Limit' => $limit->getLimit(),
                    'X-RateLimit-Remaining' => $limit->getRemainingTokens(),
                    'Retry-After' => $limit->getRetryAfter()->getTimestamp() - time(),
                ]
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $dto = new CreateShortUrlRequest(
            url: $data['url'] ?? '',
            customAlias: isset($data['customAlias']) ? (string) $data['customAlias'] : null,
            expiresAt: isset($data['expiresAt']) ? (string) $data['expiresAt'] : null,
        );

        $violations = $this->validator->validate($dto);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $shortUrl = $this->shortUrlService->create($dto);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(
            $this->serialize($shortUrl, $request),
            Response::HTTP_CREATED,
            [
                'X-RateLimit-Limit' => $limit->getLimit(),
                'X-RateLimit-Remaining' => $limit->getRemainingTokens(),
            ]
        );
    }

    #[Route('/urls/{shortCode}', methods: ['GET'], requirements: ['shortCode' => '[a-zA-Z0-9_-]+'])]
    public function stats(string $shortCode, Request $request): JsonResponse
    {
        $shortUrl = $this->repository->findByShortCode($shortCode);

        if (null === $shortUrl) {
            return $this->json(['error' => 'Short URL not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serialize($shortUrl, $request));
    }

    #[Route('/urls/{shortCode}', methods: ['DELETE'], requirements: ['shortCode' => '[a-zA-Z0-9_-]+'])]
    public function deactivate(string $shortCode): JsonResponse
    {
        $shortUrl = $this->repository->findByShortCode($shortCode);

        if (null === $shortUrl) {
            return $this->json(['error' => 'Short URL not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$shortUrl->isActive()) {
            return $this->json(['error' => 'Short URL is already inactive.'], Response::HTTP_CONFLICT);
        }

        $this->shortUrlService->deactivate($shortUrl);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array{id: int|null, shortCode: string, shortUrl: string, originalUrl: string, createdAt: string, expiresAt: string|null, clickCount: int, isActive: bool} */
    private function serialize(ShortUrl $shortUrl, Request $request): array
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        return [
            'id' => $shortUrl->getId(),
            'shortCode' => $shortUrl->getShortCode(),
            'shortUrl' => $baseUrl.'/'.$shortUrl->getShortCode(),
            'originalUrl' => $shortUrl->getOriginalUrl(),
            'createdAt' => $shortUrl->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $shortUrl->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'clickCount' => $shortUrl->getClickCount(),
            'isActive' => $shortUrl->isActive(),
        ];
    }
}

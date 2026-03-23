<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShortUrl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShortUrl>
 */
class ShortUrlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShortUrl::class);
    }

    public function findByShortCode(string $shortCode): ?ShortUrl
    {
        return $this->findOneBy(['shortCode' => $shortCode]);
    }

    public function findActiveByShortCode(string $shortCode): ?ShortUrl
    {
        return $this->createQueryBuilder('s')
            ->where('s.shortCode = :code')
            ->andWhere('s.isActive = true')
            ->setParameter('code', $shortCode)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function shortCodeExists(string $shortCode): bool
    {
        return $this->count(['shortCode' => $shortCode]) > 0;
    }

    public function incrementClickCount(string $shortCode): void
    {
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.clickCount', 's.clickCount + 1')
            ->where('s.shortCode = :code')
            ->setParameter('code', $shortCode)
            ->getQuery()
            ->execute();
    }
}

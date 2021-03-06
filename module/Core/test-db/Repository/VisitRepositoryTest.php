<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Repository;

use Cake\Chronos\Chronos;
use Doctrine\Common\Collections\ArrayCollection;
use Shlinkio\Shlink\Common\Util\DateRange;
use Shlinkio\Shlink\Core\Entity\Domain;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Entity\Tag;
use Shlinkio\Shlink\Core\Entity\Visit;
use Shlinkio\Shlink\Core\Entity\VisitLocation;
use Shlinkio\Shlink\Core\Model\ShortUrlMeta;
use Shlinkio\Shlink\Core\Model\Visitor;
use Shlinkio\Shlink\Core\Repository\VisitRepository;
use Shlinkio\Shlink\IpGeolocation\Model\Location;
use Shlinkio\Shlink\TestUtils\DbTest\DatabaseTestCase;

use function Functional\map;
use function range;
use function sprintf;

class VisitRepositoryTest extends DatabaseTestCase
{
    protected const ENTITIES_TO_EMPTY = [
        VisitLocation::class,
        Visit::class,
        ShortUrl::class,
        Domain::class,
        Tag::class,
    ];

    private VisitRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->getEntityManager()->getRepository(Visit::class);
    }

    /**
     * @test
     * @dataProvider provideBlockSize
     */
    public function findVisitsReturnsProperVisits(int $blockSize): void
    {
        $shortUrl = new ShortUrl('');
        $this->getEntityManager()->persist($shortUrl);
        $countIterable = function (iterable $results): int {
            $resultsCount = 0;
            foreach ($results as $value) {
                $resultsCount++;
            }

            return $resultsCount;
        };

        for ($i = 0; $i < 6; $i++) {
            $visit = new Visit($shortUrl, Visitor::emptyInstance());

            if ($i >= 2) {
                $location = new VisitLocation(Location::emptyInstance());
                $this->getEntityManager()->persist($location);
                $visit->locate($location);
            }

            $this->getEntityManager()->persist($visit);
        }
        $this->getEntityManager()->flush();

        $withEmptyLocation = $this->repo->findVisitsWithEmptyLocation($blockSize);
        $unlocated = $this->repo->findUnlocatedVisits($blockSize);
        $all = $this->repo->findAllVisits($blockSize);

        // Important! assertCount will not work here, as this iterable object loads data dynamically and the count
        // is 0 if not iterated
        self::assertEquals(2, $countIterable($unlocated));
        self::assertEquals(4, $countIterable($withEmptyLocation));
        self::assertEquals(6, $countIterable($all));
    }

    public function provideBlockSize(): iterable
    {
        return map(range(1, 10), fn (int $value) => [$value]);
    }

    /** @test */
    public function findVisitsByShortCodeReturnsProperData(): void
    {
        [$shortCode, $domain] = $this->createShortUrlsAndVisits();

        self::assertCount(0, $this->repo->findVisitsByShortCode('invalid'));
        self::assertCount(6, $this->repo->findVisitsByShortCode($shortCode));
        self::assertCount(3, $this->repo->findVisitsByShortCode($shortCode, $domain));
        self::assertCount(2, $this->repo->findVisitsByShortCode($shortCode, null, new DateRange(
            Chronos::parse('2016-01-02'),
            Chronos::parse('2016-01-03'),
        )));
        self::assertCount(4, $this->repo->findVisitsByShortCode($shortCode, null, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
        self::assertCount(1, $this->repo->findVisitsByShortCode($shortCode, $domain, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
        self::assertCount(3, $this->repo->findVisitsByShortCode($shortCode, null, null, 3, 2));
        self::assertCount(2, $this->repo->findVisitsByShortCode($shortCode, null, null, 5, 4));
        self::assertCount(1, $this->repo->findVisitsByShortCode($shortCode, $domain, null, 3, 2));
    }

    /** @test */
    public function countVisitsByShortCodeReturnsProperData(): void
    {
        [$shortCode, $domain] = $this->createShortUrlsAndVisits();

        self::assertEquals(0, $this->repo->countVisitsByShortCode('invalid'));
        self::assertEquals(6, $this->repo->countVisitsByShortCode($shortCode));
        self::assertEquals(3, $this->repo->countVisitsByShortCode($shortCode, $domain));
        self::assertEquals(2, $this->repo->countVisitsByShortCode($shortCode, null, new DateRange(
            Chronos::parse('2016-01-02'),
            Chronos::parse('2016-01-03'),
        )));
        self::assertEquals(4, $this->repo->countVisitsByShortCode($shortCode, null, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
        self::assertEquals(1, $this->repo->countVisitsByShortCode($shortCode, $domain, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
    }

    /** @test */
    public function findVisitsByTagReturnsProperData(): void
    {
        $foo = new Tag('foo');
        $this->getEntityManager()->persist($foo);

        /** @var ShortUrl $shortUrl */
        [,, $shortUrl] = $this->createShortUrlsAndVisits(false);
        /** @var ShortUrl $shortUrl2 */
        [,, $shortUrl2] = $this->createShortUrlsAndVisits(false);
        /** @var ShortUrl $shortUrl3 */
        [,, $shortUrl3] = $this->createShortUrlsAndVisits(false);

        $shortUrl->setTags(new ArrayCollection([$foo]));
        $shortUrl2->setTags(new ArrayCollection([$foo]));
        $shortUrl3->setTags(new ArrayCollection([$foo]));

        $this->getEntityManager()->flush();

        self::assertCount(0, $this->repo->findVisitsByTag('invalid'));
        self::assertCount(18, $this->repo->findVisitsByTag((string) $foo));
        self::assertCount(6, $this->repo->findVisitsByTag((string) $foo, new DateRange(
            Chronos::parse('2016-01-02'),
            Chronos::parse('2016-01-03'),
        )));
        self::assertCount(12, $this->repo->findVisitsByTag((string) $foo, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
    }

    /** @test */
    public function countVisitsByTagReturnsProperData(): void
    {
        $foo = new Tag('foo');
        $this->getEntityManager()->persist($foo);

        /** @var ShortUrl $shortUrl */
        [,, $shortUrl] = $this->createShortUrlsAndVisits(false);
        /** @var ShortUrl $shortUrl2 */
        [,, $shortUrl2] = $this->createShortUrlsAndVisits(false);

        $shortUrl->setTags(new ArrayCollection([$foo]));
        $shortUrl2->setTags(new ArrayCollection([$foo]));

        $this->getEntityManager()->flush();

        self::assertEquals(0, $this->repo->countVisitsByTag('invalid'));
        self::assertEquals(12, $this->repo->countVisitsByTag((string) $foo));
        self::assertEquals(4, $this->repo->countVisitsByTag((string) $foo, new DateRange(
            Chronos::parse('2016-01-02'),
            Chronos::parse('2016-01-03'),
        )));
        self::assertEquals(8, $this->repo->countVisitsByTag((string) $foo, new DateRange(
            Chronos::parse('2016-01-03'),
        )));
    }

    private function createShortUrlsAndVisits(bool $withDomain = true): array
    {
        $shortUrl = new ShortUrl('');
        $domain = 'example.com';
        $shortCode = $shortUrl->getShortCode();
        $this->getEntityManager()->persist($shortUrl);

        for ($i = 0; $i < 6; $i++) {
            $visit = new Visit(
                $shortUrl,
                Visitor::emptyInstance(),
                true,
                Chronos::parse(sprintf('2016-01-0%s', $i + 1)),
            );
            $this->getEntityManager()->persist($visit);
        }

        if ($withDomain) {
            $shortUrlWithDomain = new ShortUrl('', ShortUrlMeta::fromRawData([
                'customSlug' => $shortCode,
                'domain' => $domain,
            ]));
            $this->getEntityManager()->persist($shortUrlWithDomain);

            for ($i = 0; $i < 3; $i++) {
                $visit = new Visit(
                    $shortUrlWithDomain,
                    Visitor::emptyInstance(),
                    true,
                    Chronos::parse(sprintf('2016-01-0%s', $i + 1)),
                );
                $this->getEntityManager()->persist($visit);
            }
            $this->getEntityManager()->flush();
        }

        return [$shortCode, $domain, $shortUrl];
    }
}

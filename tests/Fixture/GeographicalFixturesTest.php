<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Tests\Fixture;

use PHPUnit\Framework\Attributes\Test;
use Sylius\Bundle\FixturesBundle\Fixture\FixtureInterface;
use Sylius\Bundle\FixturesBundle\Fixture\FixtureRegistryInterface;
use Sylius\Bundle\FixturesBundle\Listener\ListenerRegistryInterface;
use Sylius\Bundle\FixturesBundle\Loader\SuiteLoaderInterface;
use Sylius\Bundle\FixturesBundle\Suite\Suite;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMemberInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class GeographicalFixturesTest extends KernelTestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $kernel = static::bootKernel();
        $this->container = $kernel->getContainer()->get('test.service_container', ContainerInterface::NULL_ON_INVALID_REFERENCE) ?? $kernel->getContainer();
    }

    #[Test]
    public function zones_can_reference_countries_loaded_by_another_geographical_fixture(): void
    {
        $this->loadGeographicalFixtures(
            ['countries' => ['US', 'DK', 'SE']],
            [
                'countries' => [],
                'zones' => [
                    'zone_tax_25' => ['name' => 'zone_tax_25', 'scope' => 'tax', 'countries' => ['DK', 'SE']],
                ],
            ],
        );

        $this->assertZoneMembers('zone_tax_25', ZoneInterface::TYPE_COUNTRY, ['DK', 'SE']);
    }

    #[Test]
    public function countries_loaded_by_another_geographical_fixture_are_not_created_again(): void
    {
        $this->loadGeographicalFixtures(
            ['countries' => ['US', 'DK', 'SE']],
            [
                'countries' => ['DK', 'SE'],
                'zones' => [
                    'DK' => ['name' => 'Denmark', 'scope' => 'shipping', 'countries' => ['DK']],
                    'SE' => ['name' => 'Sweden', 'scope' => 'shipping', 'countries' => ['SE']],
                ],
            ],
        );

        $countryCodes = array_map(
            static fn (CountryInterface $country): ?string => $country->getCode(),
            $this->container->get('sylius.repository.country')->findAll(),
        );
        sort($countryCodes);

        $this->assertSame(['DK', 'SE', 'US'], $countryCodes);
        $this->assertZoneMembers('DK', ZoneInterface::TYPE_COUNTRY, ['DK']);
        $this->assertZoneMembers('SE', ZoneInterface::TYPE_COUNTRY, ['SE']);
    }

    #[Test]
    public function provinces_and_zones_can_reference_data_loaded_by_another_geographical_fixture(): void
    {
        $this->loadGeographicalFixtures(
            [
                'countries' => ['US'],
                'provinces' => ['US' => ['US-CA' => 'California']],
                'zones' => ['US' => ['name' => 'United States', 'countries' => ['US']]],
            ],
            [
                'countries' => [],
                'provinces' => ['US' => ['US-CA' => 'California', 'US-NY' => 'New York']],
                'zones' => [
                    'WEST-COAST' => ['name' => 'West Coast', 'provinces' => ['US-CA']],
                    'AMERICA' => ['name' => 'America', 'zones' => ['US']],
                ],
            ],
        );

        /** @var CountryInterface $country */
        $country = $this->container->get('sylius.repository.country')->findOneBy(['code' => 'US']);
        $provinceCodes = $country->getProvinces()->map(static fn ($province): ?string => $province->getCode())->toArray();
        sort($provinceCodes);

        $this->assertSame(['US-CA', 'US-NY'], $provinceCodes);
        $this->assertCount(2, $this->container->get('sylius.repository.province')->findAll());
        $this->assertZoneMembers('WEST-COAST', ZoneInterface::TYPE_PROVINCE, ['US-CA']);
        $this->assertZoneMembers('AMERICA', ZoneInterface::TYPE_ZONE, ['US']);
    }

    #[Test]
    public function zone_members_that_are_neither_defined_nor_loaded_are_still_rejected(): void
    {
        try {
            $this->loadGeographicalFixtures(
                [
                    'countries' => ['US'],
                    'provinces' => ['US' => ['US-CA' => 'California']],
                ],
                [
                    'countries' => [],
                    'provinces' => ['US' => ['US-NY' => 'New York']],
                    'zones' => ['EAST-COAST' => ['name' => 'East Coast', 'provinces' => ['US-NY', 'US-MA']]],
                ],
            );

            $this->fail('Loading a zone with an unknown member should fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('An exception was thrown during loading zone "East Coast" with code "EAST-COAST"!', $exception->getMessage());
            $this->assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
            $this->assertSame('Could not find province "US-MA", defined ones are: US-NY!', $exception->getPrevious()->getMessage());
        }
    }

    /** @param array<string, mixed> ...$fixturesOptions */
    private function loadGeographicalFixtures(array ...$fixturesOptions): void
    {
        $fixtureRegistry = $this->container->get(FixtureRegistryInterface::class);
        $listenerRegistry = $this->container->get(ListenerRegistryInterface::class);
        /** @var SuiteLoaderInterface $suiteLoader */
        $suiteLoader = $this->container->get(SuiteLoaderInterface::class);

        /** @var FixtureInterface&ConfigurationInterface $geographicalFixture */
        $geographicalFixture = $fixtureRegistry->getFixture('geographical');

        $suite = new Suite('test');
        $suite->addListener($listenerRegistry->getListener('orm_purger'), ['mode' => 'delete', 'exclude' => [], 'managers' => [null]]);

        $priority = count($fixturesOptions);
        foreach ($fixturesOptions as $fixtureOptions) {
            $suite->addFixture(
                $geographicalFixture,
                (new Processor())->processConfiguration($geographicalFixture, [$fixtureOptions]),
                $priority--,
            );
        }

        $suiteLoader->load($suite);
    }

    /** @param string[] $expectedMemberCodes */
    private function assertZoneMembers(string $zoneCode, string $expectedType, array $expectedMemberCodes): void
    {
        /** @var ZoneInterface|null $zone */
        $zone = $this->container->get('sylius.repository.zone')->findOneBy(['code' => $zoneCode]);
        $this->assertNotNull($zone, sprintf('Zone "%s" has not been loaded.', $zoneCode));
        $this->assertSame($expectedType, $zone->getType());

        $memberCodes = $zone->getMembers()->map(static fn (ZoneMemberInterface $member): ?string => $member->getCode())->toArray();
        sort($memberCodes);

        $this->assertSame($expectedMemberCodes, $memberCodes);
    }
}

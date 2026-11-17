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

namespace Sylius\Bundle\CoreBundle\Fixture;

use Doctrine\Persistence\ObjectManager;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Addressing\Factory\ZoneFactoryInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ProvinceInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Intl\Countries;
use Webmozart\Assert\Assert;

class GeographicalFixture extends AbstractFixture
{
    /**
     * @param FactoryInterface<CountryInterface> $countryFactory
     * @param FactoryInterface<ProvinceInterface> $provinceFactory
     * @param ZoneFactoryInterface<ZoneInterface> $zoneFactory
     * @param RepositoryInterface<CountryInterface>|null $countryRepository
     * @param RepositoryInterface<ProvinceInterface>|null $provinceRepository
     * @param RepositoryInterface<ZoneInterface>|null $zoneRepository
     */
    public function __construct(
        private FactoryInterface $countryFactory,
        private ObjectManager $countryManager,
        private FactoryInterface $provinceFactory,
        private ObjectManager $provinceManager,
        private ZoneFactoryInterface $zoneFactory,
        private ObjectManager $zoneManager,
        private ?RepositoryInterface $countryRepository = null,
        private ?RepositoryInterface $provinceRepository = null,
        private ?RepositoryInterface $zoneRepository = null,
    ) {
        if (null === $this->countryRepository) {
            trigger_deprecation(
                'sylius/core-bundle',
                '2.3',
                'Not passing a "%s" as "%s" to "%s" is deprecated and will be required in Sylius 3.0.',
                RepositoryInterface::class,
                '$countryRepository',
                self::class,
            );
        }

        if (null === $this->provinceRepository) {
            trigger_deprecation(
                'sylius/core-bundle',
                '2.3',
                'Not passing a "%s" as "%s" to "%s" is deprecated and will be required in Sylius 3.0.',
                RepositoryInterface::class,
                '$provinceRepository',
                self::class,
            );
        }

        if (null === $this->zoneRepository) {
            trigger_deprecation(
                'sylius/core-bundle',
                '2.3',
                'Not passing a "%s" as "%s" to "%s" is deprecated and will be required in Sylius 3.0.',
                RepositoryInterface::class,
                '$zoneRepository',
                self::class,
            );
        }
    }

    public function load(array $options): void
    {
        $this->loadCountriesWithProvinces($options['countries'], $options['provinces']);
        $this->loadZones($options['zones'], $this->provideZoneValidator($options));

        $this->countryManager->flush();
        $this->provinceManager->flush();
        $this->zoneManager->flush();
    }

    public function getName(): string
    {
        return 'geographical';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNodeBuilder = $optionsNode->children();

        $optionsNodeBuilder
            ->arrayNode('countries')
                ->performNoDeepMerging()
                ->defaultValue(array_keys(Countries::getNames()))
                ->scalarPrototype()
        ;

        $provinceNode = $optionsNodeBuilder
            ->arrayNode('provinces')
                ->normalizeKeys(false)
                ->useAttributeAsKey('code')
                ->arrayPrototype()
        ;

        $provinceNode
            ->performNoDeepMerging()
            ->normalizeKeys(false)
            ->useAttributeAsKey('code')
            ->scalarPrototype()
        ;

        $zoneNode = $optionsNodeBuilder
            ->arrayNode('zones')
                ->normalizeKeys(false)
                ->useAttributeAsKey('code')
                ->arrayPrototype()
        ;

        $zoneNode
            ->performNoDeepMerging()
            ->children()
                ->scalarNode('name')->cannotBeEmpty()->end()
                ->arrayNode('countries')->scalarPrototype()->end()->end()
                ->arrayNode('zones')->scalarPrototype()->end()->end()
                ->arrayNode('provinces')->scalarPrototype()->end()->end()
                ->scalarNode('scope')->end()
                ->integerNode('priority')->end()
        ;

        $zoneNode
            ->validate()
                ->ifTrue(function (array $zone): bool {
                    $filledTypes = 0;
                    $filledTypes += empty($zone['countries']) ? 0 : 1;
                    $filledTypes += empty($zone['zones']) ? 0 : 1;
                    $filledTypes += empty($zone['provinces']) ? 0 : 1;

                    return $filledTypes !== 1;
                })
                ->thenInvalid('Zone must have only one type of members ("countries", "zones", "provinces")')
        ;
    }

    /**
     * @param array<string> $countriesCodes
     * @param array<string, string[]> $countriesProvinces
     */
    private function loadCountriesWithProvinces(array $countriesCodes, array $countriesProvinces): void
    {
        $countries = [];
        foreach ($countriesCodes as $countryCode) {
            $country = $this->findCountry($countryCode);

            if (null === $country) {
                /** @var CountryInterface $country */
                $country = $this->countryFactory->createNew();
                $country->enable();
                $country->setCode($countryCode);

                $this->countryManager->persist($country);
            }

            $countries[$countryCode] = $country;
        }

        foreach ($countriesProvinces as $countryCode => $provinces) {
            $country = $countries[$countryCode] ?? $this->findCountry($countryCode);
            Assert::notNull($country, sprintf('Cannot create provinces for unexisting country "%s"!', $countryCode));

            $this->loadProvincesForCountry($provinces, $country);
        }
    }

    /** @param array<string, mixed> $zones */
    private function loadZones(array $zones, \Closure $zoneValidator): void
    {
        $priorityCounter = 0;

        foreach ($zones as $zoneCode => $zoneOptions) {
            $zoneName = $zoneOptions['name'];

            try {
                $zoneValidator($zoneOptions);

                $zoneType = $this->getZoneType($zoneOptions);
                $zoneMembers = $this->getZoneMembers($zoneOptions);

                $zone = $this->zoneFactory->createWithMembers($zoneMembers);
                $zone->setCode($zoneCode);
                $zone->setName($zoneName);
                $zone->setType($zoneType);
                $zone->setPriority($zoneOptions['priority'] ?? $priorityCounter++);

                if (isset($zoneOptions['scope'])) {
                    $zone->setScope($zoneOptions['scope']);
                }

                $this->zoneManager->persist($zone);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'An exception was thrown during loading zone "%s" with code "%s"!',
                    $zoneName,
                    $zoneCode,
                ), 0, $exception);
            }
        }
    }

    /** @param array<string, string> $provinces */
    private function loadProvincesForCountry(array $provinces, CountryInterface $country): void
    {
        $existingProvincesCodes = $country->getProvinces()->map(
            fn (ProvinceInterface $province): ?string => $province->getCode(),
        )->toArray();

        foreach ($provinces as $provinceCode => $provinceName) {
            if (in_array($provinceCode, $existingProvincesCodes, true)) {
                continue;
            }

            /** @var ProvinceInterface $province */
            $province = $this->provinceFactory->createNew();

            $province->setCode($provinceCode);
            $province->setName($provinceName);

            $country->addProvince($province);

            $this->provinceManager->persist($province);
        }
    }

    /**
     * @see ZoneInterface
     *
     * @param array<string, mixed> $zoneOptions
     *
     * @throws \InvalidArgumentException
     */
    private function getZoneType(array $zoneOptions): string
    {
        return match (true) {
            count($zoneOptions['countries']) > 0 => ZoneInterface::TYPE_COUNTRY,
            count($zoneOptions['provinces']) > 0 => ZoneInterface::TYPE_PROVINCE,
            count($zoneOptions['zones']) > 0 => ZoneInterface::TYPE_ZONE,
            default => throw new \InvalidArgumentException('Cannot resolve zone type!'),
        };
    }

    /**
     * @param array<string, mixed> $zoneOptions
     *
     * @return array<string, mixed>
     */
    private function getZoneMembers(array $zoneOptions): array
    {
        $zoneType = $this->getZoneType($zoneOptions);

        return match ($zoneType) {
            ZoneInterface::TYPE_COUNTRY => $zoneOptions['countries'],
            ZoneInterface::TYPE_PROVINCE => $zoneOptions['provinces'],
            ZoneInterface::TYPE_ZONE => $zoneOptions['zones'],
            default => throw new \InvalidArgumentException('Cannot resolve zone members!'),
        };
    }

    /** @param array<string, mixed> $options */
    private function provideZoneValidator(array $options): \Closure
    {
        $memberValidators = [
            ZoneInterface::TYPE_COUNTRY => function (string $countryCode) use ($options): void {
                if (in_array($countryCode, $options['countries'], true) || null !== $this->findCountry($countryCode)) {
                    return;
                }

                throw new \InvalidArgumentException(sprintf(
                    'Could not find country "%s", defined ones are: %s!',
                    $countryCode,
                    implode(', ', $options['countries']),
                ));
            },
            ZoneInterface::TYPE_PROVINCE => function (string $provinceCode) use ($options): void {
                $foundProvinces = [];
                foreach ($options['provinces'] as $provinces) {
                    if (isset($provinces[$provinceCode])) {
                        return;
                    }

                    $foundProvinces = array_merge($foundProvinces, array_keys($provinces));
                }

                if (null !== $this->provinceRepository?->findOneBy(['code' => $provinceCode])) {
                    return;
                }

                throw new \InvalidArgumentException(sprintf(
                    'Could not find province "%s", defined ones are: %s!',
                    $provinceCode,
                    implode(', ', $foundProvinces),
                ));
            },
            ZoneInterface::TYPE_ZONE => function (string $zoneCode) use ($options): void {
                if (isset($options['zones'][$zoneCode]) || null !== $this->zoneRepository?->findOneBy(['code' => $zoneCode])) {
                    return;
                }

                throw new \InvalidArgumentException(sprintf(
                    'Could not find zone "%s", defined ones are: %s!',
                    $zoneCode,
                    implode(', ', array_keys($options['zones'])),
                ));
            },
        ];

        return function (array $zoneOptions) use ($memberValidators): void {
            $zoneType = $this->getZoneType($zoneOptions);
            $zoneMembers = $this->getZoneMembers($zoneOptions);

            foreach ($zoneMembers as $zoneMember) {
                $memberValidators[$zoneType]($zoneMember);
            }
        };
    }

    private function findCountry(string $countryCode): ?CountryInterface
    {
        return $this->countryRepository?->findOneBy(['code' => $countryCode]);
    }
}

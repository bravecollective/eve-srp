<?php

declare(strict_types=1);

namespace Brave\EveSrp\Provider;

use Brave\EveSrp\SrpException;
use Brave\NeucoreApi\Api\ApplicationApi;
use Brave\NeucoreApi\ApiException;
use Brave\NeucoreApi\Model\Group;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/** @noinspection PhpUnused */
class NeucoreGroupProvider implements GroupProviderInterface
{
    /**
     * @var ApplicationApi
     */
    private $api;

    public function __construct(ContainerInterface $container)
    {
        $this->api = $container->get(ApplicationApi::class);
    }

    public function getGroups(int $eveCharacterId): array
    {
        // get groups from Core
        try {
            $groups = $this->api->groupsV2($eveCharacterId);
        } catch (ApiException | InvalidArgumentException $e) {
            throw new SrpException('NeucoreGroupProvider::getGroups: ' . $e->getMessage());
        }

        return array_map(function (Group $group) {
            return $group->getName();
        }, $groups);
    }

    /**
     * Returns all groups that a character can have.
     *
     * @throws SrpException
     */
    public function getAvailableGroups(): array
    {
        try {
            $app = $this->api->showV1();
        } catch (ApiException | InvalidArgumentException $e) {
            throw new SrpException('NeucoreGroupProvider::getAvailableGroups: ' . $e->getMessage());
        }

        return array_map(function (Group $group) {
            return $group->getName();
        }, $app->getGroups());
    }
}
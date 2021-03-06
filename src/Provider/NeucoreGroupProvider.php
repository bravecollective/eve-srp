<?php

declare(strict_types=1);

namespace EveSrp\Provider;

use EveSrp\Exception;
use Brave\NeucoreApi\Api\ApplicationApi;
use Brave\NeucoreApi\Api\ApplicationGroupsApi;
use Brave\NeucoreApi\ApiException;
use Brave\NeucoreApi\Model\Group;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/** @noinspection PhpUnused */
class NeucoreGroupProvider implements InterfaceGroupProvider
{
    /**
     * @var ApplicationApi
     */
    private $api;

    /**
     * @var ApplicationGroupsApi
     */
    private $groupApi;

    public function __construct(ContainerInterface $container)
    {
        $this->api = $container->get(ApplicationApi::class);
        $this->groupApi = $container->get(ApplicationGroupsApi::class);
    }

    public function getGroups(int $eveCharacterId): array
    {
        // get groups from Core
        try {
            $groups = $this->groupApi->groupsV2($eveCharacterId);
        } catch (ApiException | InvalidArgumentException $e) {
            throw new Exception('NeucoreGroupProvider::getGroups: ' . $e->getMessage());
        }

        return array_map(function (Group $group) {
            return $group->getName();
        }, $groups);
    }

    /**
     * Returns all groups that a character can have.
     *
     * @throws Exception
     */
    public function getAvailableGroups(): array
    {
        try {
            $app = $this->api->showV1();
        } catch (ApiException | InvalidArgumentException $e) {
            throw new Exception('NeucoreGroupProvider::getAvailableGroups: ' . $e->getMessage());
        }

        return array_map(function (Group $group) {
            return $group->getName();
        }, $app->getGroups());
    }
}

<?php

namespace EveSrp\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eve\Sso\EveAuthentication;
use EveSrp\Exception;
use EveSrp\Model\Character;
use EveSrp\Model\Division;
use EveSrp\Model\ExternalGroup;
use EveSrp\Model\Permission;
use EveSrp\Model\Request;
use EveSrp\Model\User;
use EveSrp\Provider\InterfaceCharacterProvider;
use EveSrp\Provider\InterfaceGroupProvider;
use EveSrp\Repository\CharacterRepository;
use EveSrp\Repository\DivisionRepository;
use EveSrp\Repository\ExternalGroupRepository;
use EveSrp\Repository\PermissionRepository;
use EveSrp\Repository\UserRepository;
use EveSrp\Security;
use Psr\Container\ContainerInterface;
use SlimSession\Helper;

class UserService
{
    /**
     * @var Helper
     */
    private $session;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var UserRepository
     */
    private $userRepository;

    /**
     * @var ExternalGroupRepository
     */
    private $externalGroupRepository;

    /**
     * @var CharacterRepository
     */
    private $characterRepository;

    /**
     * @var PermissionRepository
     */
    private $permissionRepository;

    /**
     * @var DivisionRepository
     */
    private $divisionRepository;

    /**
     * @var InterfaceCharacterProvider
     */
    private $characterProvider;

    /**
     * @var InterfaceGroupProvider
     */
    private $groupProvider;

    /**
     * @var User|null
     */
    private $user;

    /**
     * @var string[]
     */
    private $clientRoles = [];

    public function __construct(ContainerInterface $container) {
        $this->session = $container->get(Helper::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userRepository = $container->get(UserRepository::class);
        $this->externalGroupRepository = $container->get(ExternalGroupRepository::class);
        $this->characterRepository = $container->get(CharacterRepository::class);
        $this->permissionRepository = $container->get(PermissionRepository::class);
        $this->divisionRepository = $container->get(DivisionRepository::class);
        $this->characterProvider = $container->get(InterfaceCharacterProvider::class);
        $this->groupProvider = $container->get(InterfaceGroupProvider::class);
    }

    /**
     * Set roles of the current user (authenticated or not).
     *
     * Roles are set by the RoleProvider.
     *
     * @param string[] $roles
     */
    public function setClientRoles(array $roles): void
    {
        $this->clientRoles = $roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->clientRoles);
    }

    public function hasDivisionRole(int $divisionId, string $role): bool
    {
        if ($this->hasRole(Security::GLOBAL_ADMIN)) {
            return true;
        }

        foreach ($this->getUserPermissions() as $permission) {
            if ($permission->getDivision()->getId() === $divisionId && $permission->getRole() === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $roles
     * @return Division[]
     */
    public function getDivisionsWithRoles(array $roles): array
    {
        $divisions = [];
        foreach ($this->divisionRepository->findBy([]) as $division) {
            if ($this->hasRole(Security::GLOBAL_ADMIN)) {
                $divisions[] = $division;
                continue;
            }
            foreach ($roles as $role) {
                if ($this->hasDivisionRole($division->getId(), $role)) {
                    $divisions[] = $division;
                    continue 2;
                }
            }
        }

        return $divisions;
    }

    /**
     * Returns the logged in user, if available.
     */
    public function getAuthenticatedUser(): ?User
    {
        if ($this->user !== null) {
            return $this->user;
        }
        
        $userId = $this->session->get('userId');
        if ($userId === null) {
            return null;
        }
        $this->user = $this->userRepository->find($this->session->get('userId'));
        
        return $this->user;
    }

    /**
     * @return Permission[]
     */
    public function getUserPermissions(): array
    {
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return [];
        }
        
        $groupIds = array_map(function(ExternalGroup $group) {
            return $group->getId();
        }, $user->getExternalGroups());

        return $this->permissionRepository->findBy(['externalGroup' => $groupIds]);
    }

    /**
     * @return User The authenticated user
     */
    public function getUser(EveAuthentication $eveAuth): User
    {
        $characterId = $eveAuth->getCharacterId();

        // get or add new character with user
        $authCharacter = $this->characterRepository->find($characterId);
        if ($authCharacter === null) {
            $user = new User();
            $authCharacter = new Character();
            $authCharacter->setId($characterId);
            $authCharacter->setMain(true);
            $authCharacter->setUser($user);
            $authCharacter->setName($eveAuth->getCharacterName());
            $user->addCharacter($authCharacter);
            $user->setName($authCharacter->getName());
            $this->entityManager->persist($user);
            $this->entityManager->persist($authCharacter);
        } else {
            $user = $authCharacter->getUser();
            if ($user === null) {
                $user = new User();
                $authCharacter->setUser($user);
                $user->addCharacter($authCharacter);
                $this->entityManager->persist($user);
            }
        }

        return $user;
    }

    /**
     * Syncs EVE alts of logged in user.
     *
     * @throws Exception
     */
    public function syncCharacters(User $user, int $characterId)
    {
        # TODO if character was moved to another Core account this does not work as expected
        # use character owner hash? or add an account ID to interface? only allow login for main?

        if (count($user->getCharacters()) === 0) {
            return;
        }

        // add alts
        $allKnownCharacterIds = $this->characterProvider->getCharacters($characterId);
        foreach ($allKnownCharacterIds as $altId) {
            $alt = $this->characterRepository->find($altId);
            if ($alt === null) {
                $alt = new Character();
                $alt->setId($altId);
                $alt->setUser($user);
                $user->addCharacter($alt);
                $this->entityManager->persist($alt);
            } else {
                $oldUser = $alt->getUser();
                if ($oldUser && $oldUser->getId() !== $user->getId()) {
                    $oldUser->removeCharacter($alt);
                }
                $alt->setUser($user);
                $user->addCharacter($alt);
            }
            $alt->setName((string) $this->characterProvider->getName($alt->getId()));
        }

        // remove alts, set name of player
        $mainCharacterId = $this->characterProvider->getMain($characterId);
        foreach ($user->getCharacters() as $existingCharacter) {
            if (
                $existingCharacter->getId() !== $characterId &&
                ! in_array($existingCharacter->getId(), $allKnownCharacterIds)
            ) {
                $user->removeCharacter($existingCharacter);
                $existingCharacter->setUser(null);
            }
            if ($existingCharacter->getId() === $mainCharacterId) {
                $existingCharacter->setMain(true);
            } else {
                $existingCharacter->setMain(false);
            }
            if ($existingCharacter->getMain()) {
                $user->setName($existingCharacter->getName());
            }
        }

        // persist
        $this->entityManager->flush();
    }

    /**
     * Syncs external groups of logged in EVE character
     *
     * @param int $characterId
     * @param User $user
     * @throws Exception
     */
    public function syncGroups(int $characterId, User $user): void
    {
        $groups = $this->groupProvider->getGroups($characterId);

        // add groups
        foreach ($groups as $groupName) {
            $group = $this->externalGroupRepository->findOneBy(['name' => $groupName]);
            if ($group === null) {
                $group = (new ExternalGroup())->setName($groupName);
                $this->entityManager->persist($group);
            }
            if (! $user->hasExternalGroup($group->getName())) {
                $user->addExternalGroup($group);
            }
        }

        // remove groups
        foreach ($user->getExternalGroups() as $externalGroup) {
            if (! in_array($externalGroup->getName(), $groups)) {
                $user->removeExternalGroup($externalGroup);
            }
        }
        
        // persist
        $this->entityManager->flush();
    }
    
    public function maySee(Request $request): bool
    {
        if ($this->hasRole(Security::GLOBAL_ADMIN)) {
            return true;
        }

        if ($request->getSubmitter()->getId() === $this->getAuthenticatedUser()->getId()) {
            return true;
        }

        $divisionId = $request->getDivision() ? $request->getDivision()->getId() : null;
        if (
            $divisionId &&
            (
                $this->hasDivisionRole($divisionId, Permission::REVIEW) ||
                $this->hasDivisionRole($divisionId, Permission::PAY)
            )
        ) {
            return true;
        }

        return false;
    }
}

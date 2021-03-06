<?php

declare(strict_types=1);

namespace EveSrp\Twig;

use EveSrp\Model\Character;
use EveSrp\Model\User;
use EveSrp\Service\UserService;
use Psr\Container\ContainerInterface;

class GlobalData
{
    /**
     * @var array
     */
    private $settings;

    /**
     * @var UserService
     */
    private $userService;

    public function __construct(ContainerInterface $container)
    {
        $this->settings = $container->get('settings');
        $this->userService = $container->get(UserService::class);
    }

    /** @noinspection PhpUnused */
    public function appTitle(): string
    {
        return $this->settings['APP_TITLE'];
    }

    /** @noinspection PhpUnused */
    public function loginHint(): string
    {
        return $this->replaceMarkdownLink(htmlspecialchars($this->settings['LOGIN_HINT']));
    }

    /** @noinspection PhpUnused */
    public function footerText(): string
    {
        return $this->replaceMarkdownLink(htmlspecialchars($this->settings['FOOTER_TEXT']));
    }

    /** @noinspection PhpUnused */
    public function userName(): string
    {
        return $this->getUser() ? $this->getUser()->getName() : '';
    }

    /** @noinspection PhpUnused */
    public function characters(): array
    {
        return $this->getUser() ? array_map(function(Character $char) {
            return $char->getName();
        }, $this->getUser()->getCharacters()) : [];
    }

    private function getUser(): ?User
    {
        return $this->userService->getAuthenticatedUser();
    }

    private function replaceMarkdownLink($text)
    {
        return preg_replace(
            '/\[(.*?)\]\((.*?)\)/',
            '<a href="$2" target="_blank">$1</a> ',
            $text
        );
    }
}

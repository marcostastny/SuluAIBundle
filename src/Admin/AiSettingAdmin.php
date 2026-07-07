<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\Creation\PageCreationGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class AiSettingAdmin extends Admin
{
    public const TAB_VIEW = 'sulu_ai.settings';
    public const FORM_VIEW = 'sulu_ai.settings.form';
    public const PAGE_SEO_VIEW = 'sulu_page.page_edit_form.seo';
    public const PAGE_CONTENT_VIEW = 'sulu_page.page_edit_form.content';

    public function __construct(
        private ViewBuilderFactoryInterface $viewBuilderFactory,
        private SecurityCheckerInterface $securityChecker,
        private EntityManagerInterface $entityManager,
        private DataQueryGate $dataQueryGate,
        private PageCreationGate $pageCreationGate
    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if ($this->securityChecker->hasPermission(AiSetting::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $navigationItem = new NavigationItem('sulu_ai.settings');
            $navigationItem->setPosition(4);
            $navigationItem->setView(static::TAB_VIEW);
            $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($navigationItem);
        }
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if ($this->securityChecker->hasPermission(AiSetting::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $viewCollection->add(
                $this->viewBuilderFactory->createResourceTabViewBuilder(static::TAB_VIEW, '/ai-settings/:id')
                    ->setResourceKey(AiSetting::RESOURCE_KEY)
                    ->setAttributeDefault('id', '-')
            );
            $viewCollection->add(
                $this->viewBuilderFactory->createFormViewBuilder(static::FORM_VIEW, '/details')
                    ->setResourceKey(AiSetting::RESOURCE_KEY)
                    ->setFormKey(AiSetting::FORM_KEY)
                    ->setTabTitle('sulu_admin.details')
                    ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                    ->setParent(static::TAB_VIEW)
            );
        }

        $this->appendGenerateMetaToolbarAction($viewCollection);
        $this->appendAssistantToolbarAction($viewCollection);
    }

    private function appendGenerateMetaToolbarAction(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(AiSetting::SECURITY_CONTEXT_GENERATION, PermissionTypes::VIEW)) {
            return;
        }

        try {
            $seoView = $viewCollection->get(static::PAGE_SEO_VIEW);
            $seoView->setOption('toolbarActions', [
                ...($seoView->getView()->getOption('toolbarActions') ?? []),
                new ToolbarAction('sulu_ai.generate_meta'),
            ]);
            $viewCollection->add($seoView);
        } catch (\Exception) {
            // Page bundle / SEO view not available — nothing to do.
        }
    }

    private function appendAssistantToolbarAction(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(AiSetting::SECURITY_CONTEXT_ASSISTANT, PermissionTypes::VIEW)) {
            return;
        }

        $assistantViews = [
            static::PAGE_CONTENT_VIEW => 'content',
            static::PAGE_SEO_VIEW => 'seo',
        ];

        foreach ($assistantViews as $viewName => $tab) {
            try {
                $view = $viewCollection->get($viewName);
                $view->setOption('toolbarActions', [
                    ...($view->getView()->getOption('toolbarActions') ?? []),
                    new ToolbarAction('sulu_ai.assistant', ['tab' => $tab]),
                ]);
                $viewCollection->add($view);
            } catch (\Exception) {
                // Page bundle / view not available — nothing to do.
            }
        }
    }

    /**
     * @return mixed[]
     */
    public function getSecurityContexts()
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'AI' => [
                    AiSetting::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::EDIT,
                    ],
                    AiSetting::SECURITY_CONTEXT_GENERATION => [
                        PermissionTypes::VIEW,
                    ],
                    AiSetting::SECURITY_CONTEXT_ASSISTANT => [
                        PermissionTypes::VIEW,
                    ],
                    AiSetting::SECURITY_CONTEXT_IMAGE_GENERATION => [
                        PermissionTypes::VIEW,
                    ],
                    AiSetting::SECURITY_CONTEXT_DATA_QUERY => [
                        PermissionTypes::VIEW,
                    ],
                ],
            ],
        ];
    }

    public function getConfigKey(): ?string
    {
        return 'sulu_ai_bundle';
    }

    /**
     * @return mixed[]
     */
    public function getConfig(): ?array
    {
        try {
            $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        } catch (\Throwable) {
            // The settings table/columns may not exist yet (bundle installed but
            // schema not updated). getConfig() feeds the shared /admin/config
            // endpoint, so a thrown query would take down the entire admin UI —
            // degrade to "not configured" instead.
            return [
                'assistant' => [
                    'available' => false,
                    'agentName' => '',
                    'capabilities' => ['editing' => false, 'navigation' => false, 'dataQuery' => false, 'pageCreation' => false],
                ],
                'imageGeneration' => ['available' => false, 'models' => []],
            ];
        }

        $configured = null !== $setting && $setting->isConfigured();

        $imageAvailable = $configured && $this->securityChecker->hasPermission(
            AiSetting::SECURITY_CONTEXT_IMAGE_GENERATION,
            PermissionTypes::VIEW
        );

        // Only expose the configured models to users who may use image
        // generation — the list is otherwise withheld from the config payload.
        $models = [];
        if ($imageAvailable) {
            foreach ($setting?->getImageModels() ?? [] as $model) {
                $models[] = [
                    'label' => (string) ($model['label'] ?? ''),
                    'id' => (string) ($model['modelId'] ?? ''),
                    'supportsReference' => (bool) ($model['supportsReference'] ?? false),
                    'maxImages' => (int) ($model['maxImages'] ?? 1),
                ];
            }
        }

        $assistantAvailable = $configured && $this->securityChecker->hasPermission(
            AiSetting::SECURITY_CONTEXT_ASSISTANT,
            PermissionTypes::VIEW
        );

        return [
            'assistant' => [
                'available' => $assistantAvailable,
                'agentName' => \trim((string) $setting?->getAgentName()),
                // Drives the permission-aware chat intro: only capabilities
                // this user may actually use are advertised.
                'capabilities' => [
                    'editing' => $assistantAvailable,
                    'navigation' => $assistantAvailable,
                    'dataQuery' => $assistantAvailable && $this->dataQueryGate->isAvailable(),
                    'pageCreation' => $assistantAvailable && $this->pageCreationGate->isAvailable(),
                ],
            ],
            'imageGeneration' => [
                'available' => $imageAvailable && [] !== $models,
                'models' => $models,
            ],
        ];
    }
}

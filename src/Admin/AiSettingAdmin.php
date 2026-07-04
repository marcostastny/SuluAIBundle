<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Admin;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
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

    public function __construct(
        private ViewBuilderFactoryInterface $viewBuilderFactory,
        private SecurityCheckerInterface $securityChecker
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

    /**
     * @return mixed[]
     */
    public function getSecurityContexts()
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'AI Settings' => [
                    AiSetting::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::EDIT,
                    ],
                ],
                'AI Meta Generation' => [
                    AiSetting::SECURITY_CONTEXT_GENERATION => [
                        PermissionTypes::VIEW,
                    ],
                ],
                'AI Assistant' => [
                    AiSetting::SECURITY_CONTEXT_ASSISTANT => [
                        PermissionTypes::VIEW,
                    ],
                ],
            ],
        ];
    }
}
